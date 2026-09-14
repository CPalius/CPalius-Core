<?php

declare(strict_types=1);

namespace App\Core\Security\Flood;

use App\Core\Settings\SettingsRegistry;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Sliding-window abuse limiter. Identifiers are hashed so e-mail never appears in cache keys.
 */
final class FloodService
{
    public const EVENT_LOGIN_IP = 'login_ip';
    public const EVENT_LOGIN_USER = 'login_user';
    public const EVENT_LOCKOUT_COUNT = 'lockout_count';
    public const EVENT_REGISTER = 'register';
    public const EVENT_PASSWORD_RESET = 'password_reset';
    public const EVENT_TWOFACTOR = 'twofactor';
    public const EVENT_CSP_REPORT = 'csp_report';

    private const PREFIX = 'cp_flood_';
    private const LOCK_PREFIX = 'cp_flood_lock_';
    private const MAX_TRACKED = 512;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('security.flood_enabled', true);
    }

    /**
     * @param bool $failOpen true on login so a cache outage cannot lock operators out; false on anonymous abuse paths
     */
    public function isAllowed(string $event, string $identifier, int $limit, int $window, bool $failOpen = false): bool
    {
        try {
            if (!$this->enabled() || $limit <= 0) {
                return true;
            }

            if ($this->lockedUntil($event, $identifier) !== null) {
                return false;
            }

            return \count($this->load($event, $identifier, $window)) < $limit;
        } catch (\Throwable) {
            return $failOpen;
        }
    }

    /**
     * Records one attempt and returns how many attempts now sit inside the window.
     * Returns 0 when the attempt could not be recorded.
     */
    public function register(string $event, string $identifier, int $window): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        try {
            $item = $this->cache->getItem($this->key($event, $identifier));
            $hits = $this->prune($this->normalize($item->get()), $window);
            $hits[] = time();

            if (\count($hits) > self::MAX_TRACKED) {
                $hits = \array_slice($hits, -self::MAX_TRACKED);
            }

            $item->set($hits);
            $item->expiresAfter($window + 60);
            $this->cache->save($item);

            return \count($hits);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function count(string $event, string $identifier, int $window): int
    {
        try {
            return \count($this->load($event, $identifier, $window));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Drops the attempt window but leaves a hard lock in place. Use unlock() to lift a lockout.
     */
    public function clear(string $event, string $identifier): void
    {
        try {
            $this->cache->deleteItem($this->key($event, $identifier));
        } catch (\Throwable) {
            // Nothing to do: the window expires on its own.
        }
    }

    /**
     * Explicitly lifts a hard lock, for an operator unlocking an account by hand.
     */
    public function unlock(string $event, string $identifier): void
    {
        try {
            $this->cache->deleteItem(self::LOCK_PREFIX.$this->digest($event, $identifier));
        } catch (\Throwable) {
            // The lock expires on its own.
        }
    }

    /**
     * Hard block for a fixed duration, independent of the attempt window. Used for
     * account lockout so clearing counters does not silently unlock an account.
     */
    public function lock(string $event, string $identifier, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        try {
            $item = $this->cache->getItem(self::LOCK_PREFIX.$this->digest($event, $identifier));
            $item->set(time() + $seconds);
            $item->expiresAfter($seconds);
            $this->cache->save($item);
        } catch (\Throwable) {
            // A lock we cannot store degrades to the sliding window above.
        }
    }

    /**
     * @return int|null unix timestamp the lock expires at, or null when not locked
     */
    public function lockedUntil(string $event, string $identifier): ?int
    {
        try {
            $item = $this->cache->getItem(self::LOCK_PREFIX.$this->digest($event, $identifier));
            if (!$item->isHit()) {
                return null;
            }

            $until = (int) $item->get();

            return $until > time() ? $until : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function load(string $event, string $identifier, int $window): array
    {
        $item = $this->cache->getItem($this->key($event, $identifier));

        return $item->isHit() ? $this->prune($this->normalize($item->get()), $window) : [];
    }

    /**
     * @return list<int>
     */
    private function normalize(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $hits = [];
        foreach ($raw as $value) {
            if (\is_int($value)) {
                $hits[] = $value;
            }
        }

        return $hits;
    }

    /**
     * @param list<int> $hits
     *
     * @return list<int>
     */
    private function prune(array $hits, int $window): array
    {
        $cutoff = time() - max(1, $window);

        return array_values(array_filter($hits, static fn (int $ts): bool => $ts > $cutoff));
    }

    private function key(string $event, string $identifier): string
    {
        return self::PREFIX.$this->digest($event, $identifier);
    }

    private function digest(string $event, string $identifier): string
    {
        return hash('sha256', $event.'|'.mb_strtolower(trim($identifier)));
    }
}
