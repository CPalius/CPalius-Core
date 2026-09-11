<?php

declare(strict_types=1);

namespace App\Core\Performance;

use App\Core\OriginCache\OriginCacheStore;

/**
 * Probes that public/page-cache is writable — no Redis/Varnish required.
 */
final class OriginCacheChecker implements PerformanceBackendCheckerInterface
{
    public function __construct(
        private readonly OriginCacheStore $store,
    ) {
    }

    public function getBackendId(): string
    {
        return 'cpalius';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        $start = microtime(true);
        if (!$this->store->probeWrite()) {
            return PerformanceCheckResult::connectionFailed('aacp.performance.probe.cpalius.write_failed');
        }

        $latencyMs = (microtime(true) - $start) * 1000;
        $root = $this->store->root();

        return PerformanceCheckResult::ok(
            'aacp.performance.probe.cpalius.ok',
            ['path' => $root],
            $latencyMs,
            ['path' => $root],
        );
    }
}
