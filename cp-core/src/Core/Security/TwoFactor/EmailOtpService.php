<?php

declare(strict_types=1);

namespace App\Core\Security\TwoFactor;

use App\Core\Account\AccountMailer;
use App\Core\Mail\CpMailerService;
use App\Core\Mail\Template\CoreMailTemplates;
use App\Core\Security\Flood\FloodService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * One-time codes delivered by e-mail, as the second factor for people who will
 * not carry an authenticator app.
 *
 * Weaker than TOTP — it inherits whatever the mailbox is worth — but the honest
 * comparison is not against TOTP, it is against the accounts that would have no
 * second factor at all. What it does refuse to be is a password reset: the code
 * is short-lived, single-use, rate-limited on both issue and verify, and only
 * ever asked for AFTER the password has already been accepted.
 *
 * The code is stored as an HMAC keyed with APP_SECRET, so a database read alone
 * does not yield a usable code even though six digits are trivially brute-forced
 * against a plain hash.
 */
final class EmailOtpService
{
    public const CODE_DIGITS = 6;

    private const KEY_HASH = 'two_factor_email_hash';
    private const KEY_EXPIRES = 'two_factor_email_expires';
    private const KEY_SENT_AT = 'two_factor_email_sent_at';

    private const DEFAULT_TTL_MINUTES = 10;
    private const DEFAULT_RESEND_SECONDS = 60;

    /** Cap on codes issued per account, so a challenge page cannot be used as a mail cannon. */
    private const ISSUE_LIMIT = 10;
    private const ISSUE_WINDOW = 3600;

    public function __construct(
        private readonly AccountMailer $accountMailer,
        private readonly CpMailerService $mailer,
        private readonly SettingsRegistry $settings,
        private readonly FloodService $flood,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Whether e-mail can be offered as a method at all. Without working SMTP it
     * must not be selectable: an account enrolled on a channel the site cannot
     * use is an account locked out at the next sign-in.
     */
    public function isAvailable(): bool
    {
        return $this->mailer->canSend();
    }

    /**
     * Generates, stores and sends a fresh code.
     *
     * @return bool true when a code was queued for delivery
     */
    public function issue(User $user, ?Request $request = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $identifier = (string) $user->getId();

        if (!$this->flood->isAllowed(FloodService::EVENT_TWOFACTOR_EMAIL, $identifier, self::ISSUE_LIMIT, self::ISSUE_WINDOW, failOpen: true)) {
            return false;
        }

        $code = $this->generateCode();
        $ttl = $this->ttlSeconds();

        $user->setDataValue(self::KEY_HASH, $this->hash($code));
        $user->setDataValue(self::KEY_EXPIRES, time() + $ttl);
        $user->setDataValue(self::KEY_SENT_AT, time());
        $this->entityManager->flush();

        $this->flood->register(FloodService::EVENT_TWOFACTOR_EMAIL, $identifier, self::ISSUE_WINDOW);

        return $this->accountMailer->send($user, CoreMailTemplates::ACCOUNT_TWO_FACTOR_CODE, [
            'code' => $code,
            'minutes' => (int) round($ttl / 60),
            'ip' => (string) ($request?->getClientIp() ?? '-'),
        ]);
    }

    /**
     * Single-use: a matching code is consumed whether or not the caller goes on
     * to accept it, so a replayed POST cannot pass twice.
     */
    public function verify(User $user, string $code): bool
    {
        $stored = $user->getDataValue(self::KEY_HASH);
        $expires = $user->getDataValue(self::KEY_EXPIRES);

        if (!\is_string($stored) || $stored === '' || !\is_int($expires)) {
            return false;
        }

        if (time() > $expires) {
            $this->clear($user);

            return false;
        }

        $normalized = $this->normalize($code);
        if (\strlen($normalized) !== self::CODE_DIGITS) {
            return false;
        }

        if (!hash_equals($stored, $this->hash($normalized))) {
            return false;
        }

        $this->clear($user);
        $this->flood->clear(FloodService::EVENT_TWOFACTOR_EMAIL, (string) $user->getId());

        return true;
    }

    public function hasPendingCode(User $user): bool
    {
        $expires = $user->getDataValue(self::KEY_EXPIRES);

        return \is_string($user->getDataValue(self::KEY_HASH)) && \is_int($expires) && time() <= $expires;
    }

    /** Seconds the visitor must wait before the resend button does anything. */
    public function secondsUntilResend(User $user): int
    {
        $sentAt = $user->getDataValue(self::KEY_SENT_AT);
        if (!\is_int($sentAt)) {
            return 0;
        }

        return max(0, $this->resendSeconds() - (time() - $sentAt));
    }

    public function clear(User $user): void
    {
        $user->setDataValue(self::KEY_HASH, null);
        $user->setDataValue(self::KEY_EXPIRES, null);
        $user->setDataValue(self::KEY_SENT_AT, null);
        $this->entityManager->flush();
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->settings->get('security.twofactor_email_ttl_minutes', self::DEFAULT_TTL_MINUTES));
    }

    private function ttlSeconds(): int
    {
        return $this->ttlMinutes() * 60;
    }

    private function resendSeconds(): int
    {
        return max(0, (int) $this->settings->get('security.twofactor_email_resend_seconds', self::DEFAULT_RESEND_SECONDS));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::CODE_DIGITS - 1), self::CODE_DIGITS, '0', \STR_PAD_LEFT);
    }

    private function normalize(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->appSecret);
    }
}
