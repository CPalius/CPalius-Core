<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Probe Varnish via HTTP headers (X-Varnish / Via / Server) — there is no PHP client for varnishd.
 * Does not follow redirects: a 30x to the origin would drop the Varnish hop's signatures.
 */
final class VarnishStatusChecker implements PerformanceBackendCheckerInterface
{
    public function getBackendId(): string
    {
        return 'varnish';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        $port = $config['port'] ?? 6081;
        if (!\is_numeric($port) || (int) $port <= 0) {
            $port = 6081;
        }

        $url = HttpHeaderProbe::composeUrl(
            (string) ($config['backend_url'] ?? 'http://127.0.0.1/'),
            $port,
        );
        $timeout = (float) ($config['timeout'] ?? 2.0);

        if ($url === '' || !\filter_var($url, \FILTER_VALIDATE_URL)) {
            return PerformanceCheckResult::misconfigured('aacp.performance.probe.invalid_url');
        }

        $probe = HttpHeaderProbe::fetchHeaders($url, $timeout);

        if (!$probe['ok']) {
            return PerformanceCheckResult::connectionFailed(
                'aacp.performance.probe.url_unreachable',
                ['url' => $url],
                $probe['latencyMs'],
            );
        }

        if (!HttpHeaderProbe::hasVarnishSignature($probe['headers'])) {
            $port = (int) (\parse_url($url, \PHP_URL_PORT) ?: 0);
            if (\in_array($port, [6081, 6082], true)) {
                return PerformanceCheckResult::ok(
                    'aacp.performance.probe.varnish.ok_port',
                    ['url' => $url, 'port' => $port],
                    $probe['latencyMs'],
                );
            }

            return PerformanceCheckResult::notInstalled(
                'aacp.performance.probe.varnish.not_installed',
                ['url' => $url],
            );
        }

        return PerformanceCheckResult::ok(
            'aacp.performance.probe.varnish.ok',
            ['url' => $url],
            $probe['latencyMs'],
        );
    }
}
