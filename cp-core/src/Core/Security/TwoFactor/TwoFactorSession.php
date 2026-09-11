<?php

declare(strict_types=1);

namespace App\Core\Security\TwoFactor;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The "this session has passed its second factor" marker.
 *
 * Kept in the session rather than a cookie or the token so that the login-time
 * session migration wipes it automatically: a fresh authentication must always
 * face the challenge again.
 */
final class TwoFactorSession
{
    private const KEY_VERIFIED = '_cp_2fa_verified_at';
    private const KEY_PENDING_SECRET = '_cp_2fa_pending_secret';

    public function markVerified(SessionInterface $session): void
    {
        $session->set(self::KEY_VERIFIED, time());
        $session->remove(self::KEY_PENDING_SECRET);
    }

    public function isVerified(SessionInterface $session): bool
    {
        return \is_int($session->get(self::KEY_VERIFIED));
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::KEY_VERIFIED);
        $session->remove(self::KEY_PENDING_SECRET);
    }

    /**
     * Holds the not-yet-confirmed secret between rendering the QR code and the
     * user submitting their first code, so a reload does not silently issue a new
     * secret and invalidate the one they already scanned.
     */
    public function setPendingSecret(SessionInterface $session, string $secret): void
    {
        $session->set(self::KEY_PENDING_SECRET, $secret);
    }

    public function pendingSecret(SessionInterface $session): ?string
    {
        $value = $session->get(self::KEY_PENDING_SECRET);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
