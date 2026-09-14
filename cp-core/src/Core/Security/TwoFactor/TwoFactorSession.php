<?php

declare(strict_types=1);

namespace App\Core\Security\TwoFactor;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Marks that this session passed 2FA. Stored in the session so login migration clears it.
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

    /** Pending secret between QR render and the first submitted code, so a reload does not rotate it. */
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
