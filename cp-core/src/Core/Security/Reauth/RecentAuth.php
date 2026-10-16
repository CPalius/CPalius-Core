<?php

declare(strict_types=1);

namespace App\Core\Security\Reauth;

use App\Core\Security\Flood\FloodService;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Has this person proved who they are recently?" and the proof itself.
 *
 * The timestamp lives in the session, is set by an interactive sign-in (never by a remember-me cookie, which
 * is exactly the case this exists for) and by a successful confirmation, and ages out after
 * security.reauth_minutes. 0 minutes turns the requirement off.
 */
final class RecentAuth
{
    public const SESSION_KEY = '_cp_recent_auth_at';
    public const CONFIRM_PATH = '/aacp/confirm-identity';

    private const FLOOD_EVENT = 'reauth';
    private const FLOOD_LIMIT = 5;
    private const FLOOD_WINDOW = 600;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TwoFactorService $twoFactor,
        private readonly FloodService $flood,
    ) {
    }

    public function windowSeconds(): int
    {
        return max(0, (int) $this->settings->get('security.reauth_minutes', 10)) * 60;
    }

    public function enabled(): bool
    {
        return $this->windowSeconds() > 0;
    }

    public function isFresh(SessionInterface $session): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        $at = $session->get(self::SESSION_KEY);

        return \is_int($at) && $at <= time() && time() - $at < $this->windowSeconds();
    }

    public function mark(SessionInterface $session): void
    {
        $session->set(self::SESSION_KEY, time());
    }

    public function forget(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    public function needsSecondFactor(User $user): bool
    {
        return $this->twoFactor->isEnrolled($user);
    }

    public function isLocked(User $user): bool
    {
        return !$this->flood->isAllowed(self::FLOOD_EVENT, (string) $user->getId(), self::FLOOD_LIMIT, self::FLOOD_WINDOW);
    }

    /**
     * Checks the password and, for an enrolled account, the second factor. Counts every failure; five in ten
     * minutes lock further attempts for the rest of the window.
     */
    public function confirm(User $user, string $password, string $code, Request $request): bool
    {
        if ($this->isLocked($user)) {
            return false;
        }

        $ok = $password !== '' && $this->hasher->isPasswordValid($user, $password);
        if ($ok && $this->needsSecondFactor($user)) {
            $ok = $code !== '' && $this->twoFactor->verify($user, $code);
        }

        if (!$ok) {
            $this->flood->register(self::FLOOD_EVENT, (string) $user->getId(), self::FLOOD_WINDOW);

            return false;
        }

        $this->flood->clear(self::FLOOD_EVENT, (string) $user->getId());
        $this->mark($request->getSession());

        return true;
    }
}
