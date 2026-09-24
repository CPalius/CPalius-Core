<?php

declare(strict_types=1);

namespace Modules\DnsTools\Security;

use App\Core\Settings\SettingsRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final class DnsToolsRateLimiter
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly SettingsRegistry $settings,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{limit: int, used: int, remaining: int}
     */
    public function spamQuota(Request $request): array
    {
        $limit = $this->spamLimit($request);
        $used = $this->peek('d', $this->spamIdentity($request), 'spam-score', 86400);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
        ];
    }

    public function consumeSpamQuota(Request $request): bool
    {
        return $this->hit('d', $this->spamIdentity($request), 'spam-score', 86400, $this->spamLimit($request));
    }

    private function spamLimit(Request $request): int
    {
        $member = $this->security->getUser() instanceof UserInterface;

        return max(1, (int) $this->settings->get(
            $member ? 'dnstools.spam_member_quota' : 'dnstools.spam_guest_quota',
            $member ? 100 : 10,
        ));
    }

    private function spamIdentity(Request $request): string
    {
        return 'ip:'.hash('sha256', (string) $request->getClientIp());
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

    private function peek(string $window, string $identity, string $bucket, int $ttl): int
    {
        $slot = (string) intdiv(time(), $ttl);
        $key = 'dnstools_rl_'.hash('sha256', $window.'|'.$bucket.'|'.$identity.'|'.$slot);

        try {
            $item = $this->cache->getItem($key);

            return $item->isHit() ? (int) $item->get() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
