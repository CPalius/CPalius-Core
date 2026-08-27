<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Memcached'e ext-memcached (modern \Memcached sınıfı, eski \Memcache DEĞİL)
 * üzerinden bağlanıp getStats() ile round-trip doğrulayan prob. getStats()
 * tercih edilir çünkü tek bir sunucudan gerçek bir yanıt gelip gelmediğini
 * (versiyon dahil) doğrudan gösterir — set/get ile geçici bir test anahtarı
 * yazmak gereksiz bir yan etki olurdu.
 */
final class MemcachedConnectionTester implements PerformanceBackendCheckerInterface
{
    public function getBackendId(): string
    {
        return 'memcached';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        if (!\class_exists(\Memcached::class)) {
            return PerformanceCheckResult::notInstalled('aacp.performance.probe.memcached.not_installed');
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 11211);
        $timeout = (float) ($config['timeout'] ?? 1.5);

        try {
            $memcached = new \Memcached();
            $memcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, (int) \round($timeout * 1000));
            $memcached->addServer($host, $port);

            $start = \microtime(true);
            $stats = @$memcached->getStats();
            $latencyMs = (\microtime(true) - $start) * 1000;

            $serverKey = $host.':'.$port;
            $serverStats = $stats[$serverKey] ?? null;

            if (!\is_array($serverStats) || $serverStats === []) {
                return PerformanceCheckResult::connectionFailed(
                    'aacp.performance.probe.memcached.connection_failed',
                    ['host' => $host, 'port' => $port],
                );
            }

            $version = $serverStats['version'] ?? null;

            return PerformanceCheckResult::ok(
                'aacp.performance.probe.memcached.ok',
                ['host' => $host, 'port' => $port],
                $latencyMs,
                $version !== null ? ['version' => $version] : [],
            );
        } catch (\Throwable $e) {
            return PerformanceCheckResult::connectionFailed(
                'aacp.performance.probe.connection_error',
                ['error' => $e->getMessage()],
            );
        }
    }
}
