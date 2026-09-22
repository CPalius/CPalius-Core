<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;
use Psr\Cache\CacheItemPoolInterface;

final class ResultCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly SettingsRegistry $settings,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $tool, string $target): ?array
    {
        try {
            $item = $this->cache->getItem($this->key($tool, $target));
            if (!$item->isHit()) {
                return null;
            }
            $value = $item->get();

            return \is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function set(string $tool, string $target, array $payload): void
    {
        $ttl = max(15, (int) $this->settings->get('dnstools.cache_ttl', 120));

        try {
            $item = $this->cache->getItem($this->key($tool, $target));
            $item->set($payload);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        } catch (\Throwable) {
        }
    }

    private function key(string $tool, string $target): string
    {
        return 'dnstools_res_'.hash('sha256', $tool.'|'.strtolower($target));
    }
}
