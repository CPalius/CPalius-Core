<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Redis'e phpredis (ext-redis) üzerinden gerçek bir round-trip (PING) atarak
 * bağlantıyı doğrular. Predis (composer kütüphanesi) BİLİNÇLİ OLARAK
 * desteklenmez: proje composer.json'da böyle bir bağımlılık yok ve tek
 * amaç "sunucuda Redis çalışıyor mu" sorusuna PHP-native, ekstra bağımlılık
 * gerektirmeyen bir cevap vermek (YAGNI).
 */
final class RedisConnectionTester implements PerformanceBackendCheckerInterface
{
    public function getBackendId(): string
    {
        return 'redis';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        if (!\class_exists(\Redis::class)) {
            return PerformanceCheckResult::notInstalled('aacp.performance.probe.redis.not_installed');
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $password = (string) ($config['password'] ?? '');
        $timeout = (float) ($config['timeout'] ?? 1.5);

        $redis = new \Redis();
        $start = \microtime(true);

        try {
            $connected = @$redis->connect($host, $port, $timeout);
            if (!$connected) {
                return PerformanceCheckResult::connectionFailed(
                    'aacp.performance.probe.redis.connection_failed',
                    ['host' => $host, 'port' => $port],
                );
            }

            if ($password !== '') {
                $authed = @$redis->auth($password);
                if (!$authed) {
                    return PerformanceCheckResult::connectionFailed('aacp.performance.probe.redis.auth_failed');
                }
            }

            $pong = @$redis->ping();
            $latencyMs = (\microtime(true) - $start) * 1000;

            if ($pong !== true && $pong !== '+PONG' && $pong !== 'PONG') {
                return PerformanceCheckResult::connectionFailed('aacp.performance.probe.redis.unexpected_pong', [], $latencyMs);
            }

            $info = @$redis->info('server');
            $version = \is_array($info) ? ($info['redis_version'] ?? null) : null;

            return PerformanceCheckResult::ok(
                'aacp.performance.probe.redis.ok',
                ['host' => $host, 'port' => $port],
                $latencyMs,
                $version !== null ? ['version' => $version] : [],
            );
        } catch (\Throwable $e) {
            return PerformanceCheckResult::connectionFailed(
                'aacp.performance.probe.connection_error',
                ['error' => $e->getMessage()],
            );
        } finally {
            try {
                $redis->close();
            } catch (\Throwable) {
                // Bağlantı hiç kurulamadıysa close() da hata verebilir — yok sayılır.
            }
        }
    }
}
