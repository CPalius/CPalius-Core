<?php

declare(strict_types=1);

namespace Modules\DnsTools\Security;

use App\Core\Settings\SettingsRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

final class DnsToolsRateLimiter
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function consume(Request $request, string $bucket = 'query'): bool
    {
        $perMinute = max(3, (int) $this->settings->get('dnstools.rate_limit_minute', 20));
        $perHour = max($perMinute, (int) $this->settings->get('dnstools.rate_limit_hour', 200));
        $identity = hash('sha256', (string) $request->getClientIp());

        return $this->hit('m', $identity, $bucket, 60, $perMinute)
            && $this->hit('h', $identity, $bucket, 3600, $perHour);
    }

    private function hit(string $window, string $identity, string $bucket, int $ttl, int $limit): bool
    {
        $slot = (string) intdiv(time(), $ttl);
        $key = 'dnstools_rl_'.hash('sha256', $window.'|'.$bucket.'|'.$identity.'|'.$slot);

        try {
            $item = $this->cache->getItem($key);
            $count = $item->isHit() ? (int) $item->get() : 0;
            if ($count >= $limit) {
                return false;
            }
            $item->set($count + 1);
            $item->expiresAfter($ttl + 5);
            $this->cache->save($item);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
