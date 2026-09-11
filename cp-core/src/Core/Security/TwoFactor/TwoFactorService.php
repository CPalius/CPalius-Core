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

/**
 * TOTP second factor.
 *
 * State lives in User::$data (Law 6.3 hybrid model) rather than a side table: it
 * is per-user, never queried across users, and always loaded with the user anyway.
 * The shared secret is sealed with SecretBox so a leaked database dump does not
 * hand over the ability to mint codes.
 */
final class TwoFactorService
{
    /** Capability that marks an account as privileged for enforcement purposes. */
    public const PRIVILEGED_CAPABILITY = 'system.aacp.access';

    public const RECOVERY_CODE_COUNT = 8;

    private const KEY_SECRET = 'two_factor_secret';
    private const KEY_CONFIRMED = 'two_factor_confirmed_at';
    private const KEY_RECOVERY = 'two_factor_recovery';
    private const KEY_LAST_COUNTER = 'two_factor_last_counter';

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
    ) {
    }

    public function isEnabledGlobally(): bool
    {
        return (bool) $this->settings->get('security.twofactor_enabled', true);
    }

    public function isEnrolled(User $user): bool
    {
        return $this->secretOf($user) !== null && $this->hasConfirmation($user);
    }

    /**
     * True when the account completed enrollment but the sealed secret can no
     * longer be opened — an APP_SECRET rotation, a truncated column, a tampered
     * User::$data blob.
     *
     * This case used to be indistinguishable from "never enrolled": isEnrolled()
     * returned false, the guard fell through, and the second factor of every
     * affected account silently switched itself off while the account kept
     * reporting as protected. The caller must treat it as a broken factor and
     * force re-enrollment rather than let the session through.
     */
    public function isEnrollmentBroken(User $user): bool
    {
        $sealed = $user->getDataValue(self::KEY_SECRET);

        return \is_string($sealed) && $sealed !== ''
            && $this->hasConfirmation($user)
            && $this->secretOf($user) === null;
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
        $this->entityManager->flush();

        return $secret;
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
        $secret = $this->secretOf($user);
        if ($secret === null) {
            return null;
        }

        $counter = $this->totp->matchedCounter($secret, $code, $this->drift());
        if ($counter === null) {
            return null;
        }

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
        $this->entityManager->flush();
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
