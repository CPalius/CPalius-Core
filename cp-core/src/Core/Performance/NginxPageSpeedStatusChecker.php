<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Probe ngx_pagespeed via X-Page-Speed / X-Mod-Pagespeed response headers.
 * Same HTTP-header approach as VarnishStatusChecker — PHP cannot talk to the module directly.
 */
final class NginxPageSpeedStatusChecker implements PerformanceBackendCheckerInterface
{
    public function getBackendId(): string
    {
        return 'pagespeed';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        $url = HttpHeaderProbe::normalizeUrl((string) ($config['check_url'] ?? 'http://127.0.0.1/'));
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

        $hasPageSpeedSignature = false;
        foreach ($probe['headers'] as $header) {
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
            $probe['latencyMs'],
        );
    }
}
