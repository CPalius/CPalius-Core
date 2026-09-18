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
    private const KEY_PENDING_METHOD = '_cp_2fa_pending_method';

    public function markVerified(SessionInterface $session): void
    {
        $session->set(self::KEY_VERIFIED, time());
        $session->remove(self::KEY_PENDING_SECRET);
        $session->remove(self::KEY_PENDING_METHOD);
    }

    public function isVerified(SessionInterface $session): bool
    {
        return \is_int($session->get(self::KEY_VERIFIED));
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::KEY_VERIFIED);
        $session->remove(self::KEY_PENDING_SECRET);
        $session->remove(self::KEY_PENDING_METHOD);
    }

    /**
     * Which method the visitor picked on the setup screen. Held in the session so
     * reloading the page neither re-asks nor re-sends a code.
     */
    public function setPendingMethod(SessionInterface $session, string $method): void
    {
        $session->set(self::KEY_PENDING_METHOD, $method);
    }

    public function pendingMethod(SessionInterface $session): ?string
    {
        $value = $session->get(self::KEY_PENDING_METHOD);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function clearPending(SessionInterface $session): void
    {
        $session->remove(self::KEY_PENDING_SECRET);
        $session->remove(self::KEY_PENDING_METHOD);
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
