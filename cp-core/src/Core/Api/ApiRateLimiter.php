<?php

declare(strict_types=1);

namespace App\Core\Api;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fixed-window rate limit for /api and /hooks. Fail-closed on cache errors (deny).
 */
final class ApiRateLimiter
{
    public const WINDOW_SECONDS = 60;
    public const AUTHENTICATED_LIMIT = 60;
    public const ANONYMOUS_LIMIT = 20;
    public const HOOK_LIMIT = 30;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function consume(Request $request, ?ApiKey $apiKey, string $bucket): bool
    {
        $limit = match ($bucket) {
            'hooks' => self::HOOK_LIMIT,
            'api' => $apiKey !== null ? self::AUTHENTICATED_LIMIT : self::ANONYMOUS_LIMIT,
            default => self::ANONYMOUS_LIMIT,
        };

        $identity = $apiKey !== null
            ? 'key:'.$apiKey->id
            : 'ip:'.hash('sha256', (string) $request->getClientIp());

        $slot = (string) intdiv(time(), self::WINDOW_SECONDS);
        $cacheKey = 'cp_rl_'.hash('sha256', $bucket.'|'.$identity.'|'.$slot);

        try {
            $item = $this->cache->getItem($cacheKey);
            $count = $item->isHit() ? (int) $item->get() : 0;
            if ($count >= $limit) {
                return false;
            }
            $item->set($count + 1);
            $item->expiresAfter(self::WINDOW_SECONDS + 5);
            $this->cache->save($item);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
