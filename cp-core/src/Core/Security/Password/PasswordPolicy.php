<?php

declare(strict_types=1);

namespace App\Core\Security\Password;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single place that decides whether a password is acceptable, so registration,
 * self-service changes and operator-driven resets cannot drift apart.
 *
 * Returns already-translated messages because the callers (AccountController,
 * AccountRegistrationService) collect flat error strings.
 */
final class PasswordPolicy
{
    /** Blocks the handful of passwords that dominate every credential dump. */
    private const BUILTIN_DENYLIST = [
        'password', 'password1', 'password123', 'passw0rd', '12345678', '123456789',
        '1234567890', 'qwerty', 'qwerty123', 'qwertyuiop', 'iloveyou', 'admin',
        'admin123', 'administrator', 'letmein', 'welcome', 'welcome1', 'monkey',
        'dragon', 'sunshine', 'princess', 'football', 'baseball', 'abc123',
        'cpalius', 'parola', 'sifre123', 'sifre1234', 'test1234', 'changeme',
    ];

    private const MIN_IDENTITY_FRAGMENT = 4;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly BreachChecker $breachChecker,
        private readonly PasswordHistory $history,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function minLength(): int
    {
        $length = (int) $this->settings->get('security.password_min_length', 10);

        return max(8, min(128, $length));
    }

    public function requiredClasses(): int
    {
        $classes = (int) $this->settings->get('security.password_required_classes', 3);

        return max(1, min(4, $classes));
    }

    public function historyDepth(): int
    {
        return max(0, (int) $this->settings->get('security.password_history_depth', 5));
    }

    public function maxAgeDays(): int
    {
        return max(0, (int) $this->settings->get('security.password_max_age_days', 0));
    }

    /**
     * @param list<string> $identity E-mail, username and display names of the account
     *   the password belongs to, so it cannot simply repeat them.
     *
     * @return list<string> Translated violation messages; empty means acceptable.
     */
    public function validate(string $password, array $identity = [], ?User $user = null): array
    {
        $errors = [];

        $length = mb_strlen($password);
        $minLength = $this->minLength();
        if ($length < $minLength) {
            $errors[] = $this->translator->trans('security.password.too_short', ['min' => $minLength]);

            // Every remaining rule would just pile onto the same mistake.
            return $errors;
        }

        $classes = $this->countClasses($password);
        $required = $this->requiredClasses();
        if ($classes < $required) {
            $errors[] = $this->translator->trans('security.password.needs_classes', ['required' => $required]);
        }

        if ($this->isDenylisted($password)) {
            $errors[] = $this->translator->trans('security.password.too_common');
        }

        if ($this->resemblesIdentity($password, $identity)) {
            $errors[] = $this->translator->trans('security.password.resembles_identity');
        }

        $breachError = $this->checkBreaches($password);
        if ($breachError !== null) {
            $errors[] = $breachError;
        }

        $depth = $this->historyDepth();
        if ($user !== null && $depth > 0 && $this->history->isReused($user, $password, $depth)) {
            $errors[] = $this->translator->trans('security.password.reused', ['depth' => $depth]);
        }

        return $errors;
    }

    /**
     * Records the new hash so future changes can detect reuse. No-op when history
     * is disabled, so operators who do not want the extra rows do not get them.
     */
    public function remember(User $user, string $passwordHash): void
    {
        $depth = $this->historyDepth();
        if ($depth > 0) {
            $this->history->remember($user, $passwordHash, $depth);
        }
    }

    /**
     * True when the account's password is older than the configured rotation
     * window. Returns false when rotation is disabled or the age is unknown.
     */
    public function isExpired(User $user): bool
    {
        $maxAge = $this->maxAgeDays();
        if ($maxAge <= 0) {
            return false;
        }

        $changedAt = $user->getDataValue('password_changed_at');
        if (!\is_string($changedAt) || $changedAt === '') {
            return false;
        }

        try {
            $changed = new \DateTimeImmutable($changedAt);
        } catch (\Throwable) {
            return false;
        }

        return $changed < new \DateTimeImmutable('-'.$maxAge.' days');
    }

    private function checkBreaches(string $password): ?string
    {
        if (!(bool) $this->settings->get('security.password_breach_check', true)) {
            return null;
        }

        $occurrences = $this->breachChecker->occurrences($password);

        if ($occurrences === null) {
            // The corpus was unreachable. Failing open is the default because a
            // third-party outage should not stop people creating accounts.
            return (bool) $this->settings->get('security.password_breach_fail_closed', false)
                ? $this->translator->trans('security.password.breach_unavailable')
                : null;
        }

        return $occurrences > 0
            ? $this->translator->trans('security.password.breached', ['count' => $occurrences])
            : null;
    }

    private function countClasses(string $password): int
    {
        $classes = 0;
        $classes += preg_match('/\p{Ll}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\p{Lu}/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\d/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^\p{L}\d]/u', $password) === 1 ? 1 : 0;

        return $classes;
    }

    private function isDenylisted(string $password): bool
    {
        $normalized = mb_strtolower(trim($password));

        if (\in_array($normalized, self::BUILTIN_DENYLIST, true)) {
            return true;
        }

        // Trailing digits are the most common way of dressing up a banned word.
        $stripped = rtrim($normalized, '0123456789!');
        if ($stripped !== '' && \in_array($stripped, self::BUILTIN_DENYLIST, true)) {
            return true;
        }

        foreach ($this->customDenylist() as $entry) {
            if ($normalized === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function customDenylist(): array
    {
        $raw = (string) ($this->settings->get('security.password_denylist') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $entries = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => mb_strtolower(trim($line)), $entries),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @param list<string> $identity
     */
    private function resemblesIdentity(string $password, array $identity): bool
    {
        $normalized = mb_strtolower($password);

        foreach ($identity as $value) {
            foreach ($this->identityFragments($value) as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Splits an e-mail or display name into the meaningful words an account owner
     * would reach for. Fragments shorter than four characters are skipped, or a
     * surname like "Li" would ban half the dictionary.
     *
     * @return list<string>
     */
    private function identityFragments(string $value): array
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\d]+/u', $value) ?: [];

        return array_values(array_filter(
            $parts,
            static fn (string $part): bool => mb_strlen($part) >= self::MIN_IDENTITY_FRAGMENT,
        ));
    }
}
