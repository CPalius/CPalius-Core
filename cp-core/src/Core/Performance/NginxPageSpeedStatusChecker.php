<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * nginx ngx_pagespeed modülü de Varnish gibi PHP'den doğrudan gözlemlenemez
 * — tek gözlem noktası, modülün optimize ettiği yanıtlara eklediği
 * X-Page-Speed / X-Mod-Pagespeed header'ıdır (bkz. VarnishStatusChecker
 * docblock'undaki aynı HTTP-prob mantığı).
 */
final class NginxPageSpeedStatusChecker implements PerformanceBackendCheckerInterface
{
    public function getBackendId(): string
    {
        return 'pagespeed';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        $url = (string) ($config['check_url'] ?? 'http://127.0.0.1/');
        $timeout = (float) ($config['timeout'] ?? 2.0);

        if (!\filter_var($url, \FILTER_VALIDATE_URL)) {
            return PerformanceCheckResult::misconfigured('aacp.performance.probe.invalid_url');
        }

        $context = \stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $start = \microtime(true);
        $result = @\file_get_contents($url, false, $context);
        $latencyMs = (\microtime(true) - $start) * 1000;

        if ($result === false) {
            return PerformanceCheckResult::connectionFailed(
                'aacp.performance.probe.url_unreachable',
                ['url' => $url],
                $latencyMs,
            );
        }

        $headers = $http_response_header ?? [];
        $hasPageSpeedSignature = false;

        foreach ($headers as $header) {
            if (\stripos($header, 'X-Page-Speed:') === 0 || \stripos($header, 'X-Mod-Pagespeed:') === 0) {
                $hasPageSpeedSignature = true;
                break;
            }
        }

        if (!$hasPageSpeedSignature) {
            return PerformanceCheckResult::notInstalled('aacp.performance.probe.pagespeed.not_installed');
        }

        return PerformanceCheckResult::ok(
            'aacp.performance.probe.pagespeed.ok',
            ['url' => $url],
            $latencyMs,
        );
    }
}
