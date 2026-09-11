<?php

declare(strict_types=1);

namespace App\Core\Performance;

use App\Core\Cache\OptionalRedis;
use App\Core\OriginCache\OriginCacheStore;
use App\Core\Settings\SettingsRegistry;
use App\Entity\PerformanceBackendStatus;
use App\Repository\PerformanceBackendStatusRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Live cache totals for the AACP dashboard. Each probe is isolated.
 */
final class PerformanceInventory
{
    public function __construct(
        private readonly OriginCacheStore $originCacheStore,
        private readonly PerformanceBackendStatusRepository $statusRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly TranslatorInterface $translator,
        private readonly OptionalRedis $redis,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $statuses = $this->statusRepository->findAllAsMap();

        return [
            'cpalius' => $this->origin($statuses['cpalius'] ?? null),
            'redis' => $this->redis($statuses['redis'] ?? null),
            'memcached' => $this->memcached($statuses['memcached'] ?? null),
            'varnish' => $this->varnish($statuses['varnish'] ?? null),
            'pagespeed' => $this->pagespeed($statuses['pagespeed'] ?? null),
            'opcache' => $this->opcache(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatBytes(?int $bytes): ?string
    {
        if ($bytes === null || $bytes < 0) {
            return null;
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KiB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 2).' MiB';
        }

        return round($bytes / 1073741824, 2).' GiB';
    }

    /**
     * @return array<string, mixed>
     */
    private function origin(?PerformanceBackendStatus $status): array
    {
        try {
            $disk = $this->originCacheStore->summarize(15);
        } catch (\Throwable) {
            $disk = [
                'enabled' => false,
                'htmlCount' => 0,
                'htmlBytes' => 0,
                'assetCount' => 0,
                'assetBytes' => 0,
                'imageCount' => 0,
                'imageBytes' => 0,
                'totalBytes' => 0,
                'pages' => [],
            ];
        }

        $pages = [];
        foreach ($disk['pages'] as $page) {
            $pages[] = [
                'path' => $page['path'],
                'bytes' => $page['bytes'],
                'sizeLabel' => self::formatBytes($page['bytes']) ?? '—',
                'mtimeLabel' => (new \DateTimeImmutable())->setTimestamp($page['mtime'])->format('d.m H:i'),
            ];
        }

        return [
            'id' => 'cpalius',
            'online' => $status?->isEnabled() === true && $disk['enabled'],
            'count' => $disk['htmlCount'],
            'bytes' => $disk['totalBytes'],
            'sizeLabel' => self::formatBytes($disk['totalBytes']) ?? '0 B',
            'meta' => $this->translator->trans('aacp.dashboard.perf.origin_meta', [
                'html' => $disk['htmlCount'],
                'assets' => $disk['assetCount'],
                'images' => $disk['imageCount'],
            ]),
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function redis(?PerformanceBackendStatus $status): array
    {
        $row = [
            'id' => 'redis',
            'online' => false,
            'count' => null,
            'bytes' => null,
            'sizeLabel' => '—',
            'meta' => $status?->isEnabled()
                ? $this->translator->trans('aacp.dashboard.perf.on')
                : $this->translator->trans('aacp.dashboard.perf.off'),
        ];

        try {
            $client = $this->redis->get();
            if ($client === null || !method_exists($client, 'ping')) {
                return $row;
            }
            $pong = @$client->ping();
            if ($pong !== true && $pong !== '+PONG' && $pong !== 'PONG') {
                return $row;
            }
            $dbsize = method_exists($client, 'dbSize') ? (int) @$client->dbSize() : null;
            $info = method_exists($client, 'info') ? @$client->info('memory') : [];
            $used = \is_array($info) ? (int) ($info['used_memory'] ?? 0) : 0;
            $row['online'] = true;
            $row['count'] = $dbsize;
            $row['bytes'] = $used > 0 ? $used : null;
            $row['sizeLabel'] = self::formatBytes($row['bytes']) ?? '—';
            $row['meta'] = \is_array($info) ? (string) ($info['used_memory_human'] ?? '') : '';
        } catch (\Throwable) {
            return $row;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function memcached(?PerformanceBackendStatus $status): array
    {
        $row = [
            'id' => 'memcached',
            'online' => false,
            'count' => null,
            'bytes' => null,
            'sizeLabel' => '—',
            'meta' => $status?->isEnabled()
                ? $this->translator->trans('aacp.dashboard.perf.on')
                : $this->translator->trans('aacp.dashboard.perf.off'),
        ];

        if (!\class_exists(\Memcached::class)) {
            $row['meta'] = $this->translator->trans('aacp.dashboard.perf.ext_missing');

            return $row;
        }

        $host = (string) $this->settingsRegistry->get('performance.memcached.host', '127.0.0.1');
        $port = (int) $this->settingsRegistry->get('performance.memcached.port', 11211);

        try {
            $client = new \Memcached();
            $client->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 400);
            $client->addServer($host, $port);
            $stats = @$client->getStats();
            $client->quit();
            $server = \is_array($stats) ? ($stats[$host.':'.$port] ?? null) : null;
            if (!\is_array($server) || $server === []) {
                return $row;
            }
            $bytes = isset($server['bytes']) ? (int) $server['bytes'] : null;
            $row['online'] = true;
            $row['count'] = isset($server['curr_items']) ? (int) $server['curr_items'] : null;
            $row['bytes'] = $bytes;
            $row['sizeLabel'] = self::formatBytes($bytes) ?? '—';
            $hits = isset($server['get_hits']) ? (int) $server['get_hits'] : null;
            $row['meta'] = $hits !== null
                ? $this->translator->trans('aacp.dashboard.perf.hits', ['count' => $hits])
                : (string) ($server['version'] ?? '');
        } catch (\Throwable) {
            return $row;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function varnish(?PerformanceBackendStatus $status): array
    {
        $ttl = (new VarnishCachePolicy())->normalizeTtl(
            $this->settingsRegistry->get('performance.varnish.ttl', VarnishCachePolicy::DEFAULT_TTL),
        );
        // Varnish object counts need varnishstat on the host; PHP only sees enablement + TTL.
        $online = $status?->isEnabled() === true;

        return [
            'id' => 'varnish',
            'online' => $online,
            'count' => null,
            'bytes' => null,
            'sizeLabel' => '—',
            'meta' => $this->translator->trans('aacp.dashboard.perf.varnish_meta', ['ttl' => $ttl]),
            'countUnavailable' => true,
        ];
    }

    /**
     * PageSpeed object counts live in nginx; PHP only sees enablement + last probe.
     *
     * @return array<string, mixed>
     */
    private function pagespeed(?PerformanceBackendStatus $status): array
    {
        $tested = $status?->getLastTestedAt();

        return [
            'id' => 'pagespeed',
            'online' => $status?->isEnabled() === true,
            'count' => null,
            'bytes' => null,
            'sizeLabel' => '—',
            'meta' => $tested instanceof \DateTimeImmutable
                ? $this->translator->trans('aacp.dashboard.perf.probed', ['time' => $tested->format('d.m H:i')])
                : $this->translator->trans('aacp.dashboard.perf.not_probed'),
            'countUnavailable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function opcache(): array
    {
        $row = [
            'id' => 'opcache',
            'online' => false,
            'count' => null,
            'bytes' => null,
            'sizeLabel' => '—',
            'meta' => '',
        ];
        if (!\function_exists('opcache_get_status')) {
            return $row;
        }
        $status = @opcache_get_status(false);
        if (!\is_array($status) || !($status['opcache_enabled'] ?? false)) {
            return $row;
        }
        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        $used = isset($memory['used_memory']) ? (int) $memory['used_memory'] : null;
        $row['online'] = true;
        $row['count'] = isset($stats['num_cached_scripts']) ? (int) $stats['num_cached_scripts'] : null;
        $row['bytes'] = $used;
        $row['sizeLabel'] = self::formatBytes($used) ?? '—';
        $hit = isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 1) : null;
        $row['meta'] = $hit !== null
            ? $this->translator->trans('aacp.dashboard.perf.hit_rate', ['rate' => $hit])
            : '';

        return $row;
    }
}
