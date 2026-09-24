<?php

declare(strict_types=1);

namespace App\Core\Security\TwoFactor;

use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\RoleConfigManager;
use App\Core\Security\SecretBox;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Second factor, in two flavours the account owner picks between: a TOTP
 * authenticator app, or a one-time code e-mailed at sign-in.
 *
 * State lives in User::$data; the TOTP shared secret is sealed with SecretBox and
 * the e-mailed code is held by EmailOtpService. Recovery codes are shared by both
 * methods, so switching method never strands a printed code list.
 */
final class TwoFactorService
{
    /** Capability that marks an account as privileged for enforcement purposes. */
    public const PRIVILEGED_CAPABILITY = 'system.aacp.access';

    public const RECOVERY_CODE_COUNT = 8;

    /** Authenticator app (RFC 6238). The default, and the only method before 2.0.6. */
    public const METHOD_TOTP = 'totp';
    /** One-time code delivered to the account's e-mail address. */
    public const METHOD_EMAIL = 'email';

    private const KEY_SECRET = 'two_factor_secret';
    private const KEY_CONFIRMED = 'two_factor_confirmed_at';
    private const KEY_RECOVERY = 'two_factor_recovery';
    private const KEY_LAST_COUNTER = 'two_factor_last_counter';
    private const KEY_METHOD = 'two_factor_method';

    private const VERIFY_LIMIT = 10;
    private const VERIFY_WINDOW = 900;

    public function __construct(
        private readonly TotpGenerator $totp,
        private readonly SecretBox $secretBox,
        private readonly SettingsRegistry $settings,
        private readonly RoleConfigManager $roleConfigManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly FloodService $flood,
        private readonly SecurityEventRecorder $recorder,
        private readonly string $appSecret,
        // Optional so a container built without the mail stack — and the unit
        // tests that construct this by hand — still resolve the TOTP path.
        private readonly ?EmailOtpService $emailOtp = null,
    ) {
    }

    public function isEnabledGlobally(): bool
    {
        return (bool) $this->settings->get('security.twofactor_enabled', true);
    }

    /**
     * After a password login, ask every account for an e-mail code — without
     * enrolling them in 2FA. Skipped when SMTP cannot send, so a site without
     * mail is not locked out.
     */
    public function isLoginEmailCodeEnabled(): bool
    {
        return (bool) $this->settings->get('security.login_email_code', false)
            && $this->emailOtp !== null
            && $this->emailOtp->isAvailable();
    }

    public function needsLoginEmailChallenge(User $user): bool
    {
        return $this->isLoginEmailCodeEnabled() && !$this->isEnrolled($user);
    }

    public function issueLoginEmailCode(User $user, ?Request $request = null): bool
    {
        if (!$this->isLoginEmailCodeEnabled() || $this->emailOtp === null) {
            return false;
        }

        return $this->emailOtp->issue($user, $request);
    }

    public function verifyLoginEmailCode(User $user, string $code): bool
    {
        if ($this->emailOtp === null) {
            return false;
        }

        $identifier = (string) $user->getId();
        if (!$this->flood->isAllowed(FloodService::EVENT_TWOFACTOR, $identifier, self::VERIFY_LIMIT, self::VERIFY_WINDOW, failOpen: true)) {
            return false;
        }

        if ($this->emailOtp->verify($user, $code)) {
            $this->flood->clear(FloodService::EVENT_TWOFACTOR, $identifier);

            return true;
        }

        $this->registerFailure($user, 'login_email_mismatch');

        return false;
    }

    /**
     * The method this account enrolled with. Accounts that enrolled before
     * e-mail codes existed have no stored method and a sealed secret, so TOTP is
     * the right answer for them and the right default for everybody else.
     */
    public function method(User $user): string
    {
        $stored = $user->getDataValue(self::KEY_METHOD);

        return $stored === self::METHOD_EMAIL ? self::METHOD_EMAIL : self::METHOD_TOTP;
    }

    public function usesEmail(User $user): bool
    {
        return $this->method($user) === self::METHOD_EMAIL;
    }

    public function isEnrolled(User $user): bool
    {
        if ($this->usesEmail($user)) {
            // Deliberately not conditioned on SMTP being reachable right now: an
            // outage must not silently drop the second factor off an account.
            // A visitor stuck behind a dead mailer uses a recovery code.
            return $this->hasConfirmation($user);
        }

        return $this->secretOf($user) !== null && $this->hasConfirmation($user);
    }

    /**
     * Enrollment exists but the sealed secret cannot be opened. Treat as broken 2FA, not "never enrolled".
     */
    public function isEnrollmentBroken(User $user): bool
    {
        if ($this->usesEmail($user)) {
            return false;
        }

        $sealed = $user->getDataValue(self::KEY_SECRET);

        return \is_string($sealed) && $sealed !== ''
            && $this->hasConfirmation($user)
            && $this->secretOf($user) === null;
    }

    /**
     * Methods an account may pick from right now: the operator's choice, minus
     * e-mail when the site cannot send mail.
     *
     * @return list<string>
     */
    public function availableMethods(): array
    {
        $configured = (string) $this->settings->get('security.twofactor_methods', 'both');
        $emailUsable = $this->emailOtp !== null && $this->emailOtp->isAvailable();

        $methods = [];

        if ($configured !== 'email') {
            $methods[] = self::METHOD_TOTP;
        }

        if ($configured !== 'app' && $emailUsable) {
            $methods[] = self::METHOD_EMAIL;
        }

        // Never return an empty list: "e-mail only" plus a broken mailer would
        // otherwise leave a mandatory-2FA account with nothing to enroll in.
        return $methods === [] ? [self::METHOD_TOTP] : $methods;
    }

    public function isMethodAllowed(string $method): bool
    {
        return \in_array($method, $this->availableMethods(), true);
    }

    private function hasConfirmation(User $user): bool
    {
        $confirmed = $user->getDataValue(self::KEY_CONFIRMED);

        return \is_string($confirmed) && $confirmed !== '';
    }

    /**
     * True when this account must pass a second factor but has not enrolled yet,
     * i.e. it should be pushed into enrollment rather than allowed through.
     */
    public function isEnrollmentRequired(User $user): bool
    {
        return $this->isEnabledGlobally() && !$this->isEnrolled($user) && $this->isPrivileged($user);
    }

    public function isPrivileged(User $user): bool
    {
        if (!(bool) $this->settings->get('security.twofactor_enforce_privileged', false)) {
            return false;
        }

        $capabilities = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());

        return \in_array(self::PRIVILEGED_CAPABILITY, $capabilities, true)
            || \in_array('*', $capabilities, true);
    }

    /**
     * Issues an unconfirmed secret. Enrollment is only complete once the user has
     * proven they can generate a code from it, so a half-finished setup can never
     * lock anybody out of their own account.
     */
    public function beginEnrollment(User $user): string
    {
        $secret = $this->totp->generateSecret();

        $user->setDataValue(self::KEY_SECRET, $this->secretBox->seal($secret));
        $user->setDataValue(self::KEY_CONFIRMED, null);
        $user->setDataValue(self::KEY_RECOVERY, []);
        $user->setDataValue(self::KEY_LAST_COUNTER, null);
        $user->setDataValue(self::KEY_METHOD, self::METHOD_TOTP);
        $this->entityManager->flush();

        return $secret;
    }

    /**
     * Starts e-mail enrollment and sends the first code.
     *
     * Like beginEnrollment(), starting again wipes the previous state entirely:
     * an account enrolled on e-mail must not keep a dormant authenticator secret
     * that would still open the door. The mailer is checked BEFORE anything is
     * wiped, so the common failure — e-mail never configured — costs nothing.
     *
     * @return bool false when no code was sent; the account is then unenrolled
     *              and the setup screen offers the other method
     */
    public function beginEmailEnrollment(User $user, ?Request $request = null): bool
    {
        if ($this->emailOtp === null || !$this->emailOtp->isAvailable()) {
            return false;
        }

        $user->setDataValue(self::KEY_SECRET, null);
        $user->setDataValue(self::KEY_CONFIRMED, null);
        $user->setDataValue(self::KEY_RECOVERY, []);
        $user->setDataValue(self::KEY_LAST_COUNTER, null);
        $user->setDataValue(self::KEY_METHOD, self::METHOD_EMAIL);
        $this->entityManager->flush();

        return $this->emailOtp->issue($user, $request);
    }

    /**
     * Sends a fresh e-mail code for an account already on the e-mail method,
     * for the challenge screen and its resend button.
     */
    public function issueEmailCode(User $user, ?Request $request = null): bool
    {
        if ($this->emailOtp === null || !$this->usesEmail($user)) {
            return false;
        }

        return $this->emailOtp->issue($user, $request);
    }

    /** Seconds left on the resend cooldown, so the button can say why it is inert. */
    public function emailResendWait(User $user): int
    {
        return $this->emailOtp?->secondsUntilResend($user) ?? 0;
    }

    public function emailCodeMinutes(): int
    {
        return $this->emailOtp?->ttlMinutes() ?? 0;
    }

    public function hasPendingEmailCode(User $user): bool
    {
        return $this->emailOtp?->hasPendingCode($user) ?? false;
    }

    public function provisioningUri(User $user, string $secret, string $issuer): string
    {
        return $this->totp->provisioningUri($secret, $user->getEmail(), $issuer);
    }

    /**
     * @return list<string>|null plain recovery codes, shown exactly once, or null
     *                           when the submitted code did not match
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if ($this->usesEmail($user)) {
            if ($this->emailOtp === null || !$this->emailOtp->verify($user, $code)) {
                return null;
            }

            return $this->completeEnrollment($user, null);
        }

        $secret = $this->secretOf($user);
        if ($secret === null) {
            return null;
        }

        $counter = $this->totp->matchedCounter($secret, $code, $this->drift());
        if ($counter === null) {
            return null;
        }

        return $this->completeEnrollment($user, $counter);
    }

    /**
     * @return list<string> the plain recovery codes, shown exactly once
     */
    private function completeEnrollment(User $user, ?int $counter): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->setDataValue(self::KEY_CONFIRMED, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $user->setDataValue(self::KEY_LAST_COUNTER, $counter);
        $user->setDataValue(self::KEY_RECOVERY, $this->hashRecoveryCodes($codes));
        $this->entityManager->flush();

        return $codes;
    }

    /**
     * Accepts a TOTP code or a single-use recovery code.
     */
    public function verify(User $user, string $code): bool
    {
        $identifier = (string) $user->getId();

        if (!$this->flood->isAllowed(FloodService::EVENT_TWOFACTOR, $identifier, self::VERIFY_LIMIT, self::VERIFY_WINDOW, failOpen: true)) {
            return false;
        }

        if ($this->usesEmail($user)) {
            if ($this->emailOtp !== null && $this->emailOtp->verify($user, $code)) {
                $this->flood->clear(FloodService::EVENT_TWOFACTOR, $identifier);

                return true;
            }

            // A recovery code is the way back in when the mailbox is unreachable,
            // so it stays accepted on this path too.
            if ($this->consumeRecoveryCode($user, $code)) {
                $this->flood->clear(FloodService::EVENT_TWOFACTOR, $identifier);

                return true;
            }

            $this->registerFailure($user, 'email_mismatch');

            return false;
        }

        $secret = $this->secretOf($user);
        if ($secret === null) {
            return false;
        }

        $counter = $this->totp->matchedCounter($secret, $code, $this->drift());
        if ($counter !== null) {
            $lastCounter = $user->getDataValue(self::KEY_LAST_COUNTER);

            // A code is valid for 30 seconds; accepting it twice would let anyone
            // who shoulder-surfed or replayed the POST in through the same window.
            if (\is_int($lastCounter) && $counter <= $lastCounter) {
                $this->registerFailure($user, 'replay');

                return false;
            }

            $user->setDataValue(self::KEY_LAST_COUNTER, $counter);
            $this->entityManager->flush();
            $this->flood->clear(FloodService::EVENT_TWOFACTOR, $identifier);

            return true;
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            $this->flood->clear(FloodService::EVENT_TWOFACTOR, $identifier);

            return true;
        }

        $this->registerFailure($user, 'mismatch');

        return false;
    }

    public function disable(User $user): void
    {
        $user->setDataValue(self::KEY_SECRET, null);
        $user->setDataValue(self::KEY_CONFIRMED, null);
        $user->setDataValue(self::KEY_RECOVERY, []);
        $user->setDataValue(self::KEY_LAST_COUNTER, null);
        $user->setDataValue(self::KEY_METHOD, null);
        $this->entityManager->flush();

        // A pending code left behind would still be verifiable if the account
        // re-enrolled on e-mail before it expired.
        $this->emailOtp?->clear($user);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->setDataValue(self::KEY_RECOVERY, $this->hashRecoveryCodes($codes));
        $this->entityManager->flush();

        return $codes;
    }

    public function recoveryCodesRemaining(User $user): int
    {
        $stored = $user->getDataValue(self::KEY_RECOVERY);

        return \is_array($stored) ? \count($stored) : 0;
    }

    public function confirmedAt(User $user): ?string
    {
        $value = $user->getDataValue(self::KEY_CONFIRMED);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        $stored = $user->getDataValue(self::KEY_RECOVERY);
        if (!\is_array($stored) || $stored === []) {
            return false;
        }

        $remaining = [];
        $matched = false;

        foreach ($stored as $hash) {
            if (!\is_string($hash)) {
                continue;
            }

            if (!$matched && hash_equals($hash, $this->hashRecoveryCode($normalized))) {
                $matched = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if (!$matched) {
            return false;
        }

        $user->setDataValue(self::KEY_RECOVERY, $remaining);
        $this->entityManager->flush();

        $this->recorder->warning('twofactor_recovery_used', [
            'remaining' => \count($remaining),
        ], null, $user->getId());

        return true;
    }

    private function registerFailure(User $user, string $reason): void
    {
        $this->flood->register(FloodService::EVENT_TWOFACTOR, (string) $user->getId(), self::VERIFY_WINDOW);
        $this->recorder->record(
            SystemTelemetryLog::EVENT_TWOFACTOR_FAILED,
            SystemTelemetryLog::SEVERITY_WARNING,
            40,
            ['reason' => $reason],
            null,
            $user->getId(),
        );
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; ++$i) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5, 5);
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function hashRecoveryCodes(array $codes): array
    {
        return array_map(
            fn (string $code): string => $this->hashRecoveryCode($this->normalizeRecoveryCode($code)),
            $codes,
        );
    }

    /**
     * Recovery codes are high-entropy random strings, so a keyed hash is the right
     * primitive: a slow password hash would buy nothing against 40 bits of
     * randomness that no wordlist contains, and it would make every failed TOTP
     * attempt pay for eight bcrypt comparisons.
     */
    private function hashRecoveryCode(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, $this->appSecret);
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function secretOf(User $user): ?string
    {
        $sealed = $user->getDataValue(self::KEY_SECRET);
        if (!\is_string($sealed) || $sealed === '') {
            return null;
        }

        try {
            return $this->secretBox->open($sealed);
        } catch (\Throwable) {
            return null;
        }
    }

    private function drift(): int
    {
        return max(0, min(5, (int) $this->settings->get('security.twofactor_drift', 1)));
    }
}
