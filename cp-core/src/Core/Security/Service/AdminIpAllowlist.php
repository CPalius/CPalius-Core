<?php

declare(strict_types=1);

namespace App\Core\Security\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Addresses of administrators who are signed in right now. They are exempt from IP bans so an admin can never be
 * locked out of their own site mid-session; the entry disappears on logout and, otherwise, a few minutes after the
 * last admin request (the session going stale).
 */
final class AdminIpAllowlist
{
    private const TTL = 1800;
    private const REFRESH_AFTER = 300;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function has(string $ip): bool
    {
        try {
            return $ip !== '' && $this->cache->getItem($this->key($ip))->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    public function touch(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        try {
            $item = $this->cache->getItem($this->key($ip));
            // Rewritten at most every few minutes: an admin clicking around must not cost a cache write per request.
            if ($item->isHit() && time() - (int) $item->get() < self::REFRESH_AFTER) {
                return;
            }
            $this->cache->save($item->set(time())->expiresAfter(self::TTL));
        } catch (\Throwable) {
        }
    }

    public function forget(string $ip): void
    {
        try {
            $this->cache->deleteItem($this->key($ip));
        } catch (\Throwable) {
        }
    }

    private function key(string $ip): string
    {
        return 'admin_ip_'.hash('sha256', $ip);
    }
}
