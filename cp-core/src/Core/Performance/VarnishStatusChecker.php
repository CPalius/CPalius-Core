<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Varnish için PHP tarafında bir extension/protokol istemcisi YOKTUR
 * (Varnish admin CLI'ı ayrı bir binary protokol + secret gerektirir, bu da
 * "sadece durum + temel config" kapsamının dışında — bkz. plan). Bunun
 * yerine yapılandırılan URL'e bir HTTP HEAD isteği atılıp yanıt
 * header'larında Varnish imzası (X-Varnish veya "Via: ... varnish")
 * aranır. Bu yöntemin doğası gereği "kurulu değil" ile "bu URL'in önünde
 * değil" ayrımı HTTP üzerinden yapılamaz — ikisi de aynı 'not_installed'
 * mesajıyla raporlanır, kullanıcıya yanlış bir kesinlik iddia edilmez.
 */
final class VarnishStatusChecker implements PerformanceBackendCheckerInterface
{
    public function getBackendId(): string
    {
        return 'varnish';
    }

    public function testConnection(array $config): PerformanceCheckResult
    {
        $url = (string) ($config['backend_url'] ?? 'http://127.0.0.1/');
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
        $hasVarnishSignature = false;

        foreach ($headers as $header) {
            if (\stripos($header, 'X-Varnish:') === 0 || \stripos($header, 'Via:') === 0 && \stripos($header, 'varnish') !== false) {
                $hasVarnishSignature = true;
                break;
            }
        }

        if (!$hasVarnishSignature) {
            return PerformanceCheckResult::notInstalled('aacp.performance.probe.varnish.not_installed');
        }

        return PerformanceCheckResult::ok(
            'aacp.performance.probe.varnish.ok',
            ['url' => $url],
            $latencyMs,
        );
    }
}
