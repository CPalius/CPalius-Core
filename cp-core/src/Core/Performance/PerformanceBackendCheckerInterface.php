<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Shared contract for performance backend probes; testConnection() always returns PerformanceCheckResult (never throws).
 */
interface PerformanceBackendCheckerInterface
{
    /**
     * @return string 'redis' | 'memcached' | 'varnish' | 'pagespeed'
     */
    public function getBackendId(): string;

    /**
     * @param array<string, mixed> $config Backend-specific connection settings (e.g. Redis host/port/password/timeout).
     */
    public function testConnection(array $config): PerformanceCheckResult;
}
