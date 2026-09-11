<?php

declare(strict_types=1);

namespace App\Core\Performance;

/**
 * Shared HTTP probe for Varnish/PageSpeed: normalize a check URL and read response headers.
 * Prefers curl so the probe still works when allow_url_fopen is off.
 */
final class HttpHeaderProbe
{
    /**
     * Prefix http:// when the operator typed host:port without a scheme.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return $url;
        }

        if (!\preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return 'http://'.$url;
        }

        return $url;
    }

    /**
     * Apply an explicit listen port, replacing any port already present in $url.
     */
    public static function composeUrl(string $url, int|string|null $port = null): string
    {
        $url = self::normalizeUrl($url);
        $port = \is_numeric($port) ? (int) $port : 0;
        if ($url === '' || $port <= 0 || $port > 65535) {
            return $url;
        }

        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return \sprintf('%s://%s:%d%s%s', $scheme, $parts['host'], $port, $path, $query);
    }

    /**
     * @param list<string> $headers
     */
    public static function hasVarnishSignature(array $headers): bool
    {
        foreach ($headers as $header) {
            $lower = \strtolower($header);
            if (\str_starts_with($lower, 'x-varnish:') || \str_starts_with($lower, 'x-varnish-')) {
                return true;
            }
            if (\str_starts_with($lower, 'via:') && \str_contains($lower, 'varnish')) {
                return true;
            }
            if (\str_starts_with($lower, 'server:') && \str_contains($lower, 'varnish')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, headers: list<string>, latencyMs: float}
     */
    public static function fetchHeaders(string $url, float $timeout): array
    {
        $timeout = \max(0.5, $timeout);
        $start = \microtime(true);

        if (\function_exists('curl_init')) {
            return self::fetchWithCurl($url, $timeout, $start);
        }

        return self::fetchWithStream($url, $timeout, $start);
    }

    /**
     * @return array{ok: bool, headers: list<string>, latencyMs: float}
     */
    private static function fetchWithCurl(string $url, float $timeout, float $start): array
    {
        $seconds = (int) \max(1, \ceil($timeout));
        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'headers' => [], 'latencyMs' => self::elapsedMs($start)];
        }

        $headerLines = [];
        $bodyBytes = 0;

        \curl_setopt_array($ch, [
            \CURLOPT_HTTPGET => true,
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_HEADER => false,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT => $seconds,
            \CURLOPT_TIMEOUT => $seconds,
            \CURLOPT_USERAGENT => 'CPalius-CMF-PerformanceProbe',
            \CURLOPT_HTTPHEADER => ['Accept: */*'],
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headerLines): int {
                $trimmed = \trim($headerLine);
                if ($trimmed !== '') {
                    $headerLines[] = $trimmed;
                }

                return \strlen($headerLine);
            },
            \CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bodyBytes): int {
                $bodyBytes += \strlen($chunk);

                // Stop after a small peek so a large homepage cannot stall the probe.
                return $bodyBytes > 4096 ? 0 : \strlen($chunk);
            },
        ]);

        \curl_exec($ch);
        $errno = \curl_errno($ch);
        \curl_close($ch);

        $latencyMs = self::elapsedMs($start);
        // CURLE_WRITE_ERROR (23) is expected when WRITEFUNCTION aborts after the peek.
        $ok = $headerLines !== [] && ($errno === 0 || $errno === 23);

        return ['ok' => $ok, 'headers' => $headerLines, 'latencyMs' => $latencyMs];
    }

    /**
     * @return array{ok: bool, headers: list<string>, latencyMs: float}
     */
    private static function fetchWithStream(string $url, float $timeout, float $start): array
    {
        $context = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => "User-Agent: CPalius-CMF-PerformanceProbe\r\nAccept: */*\r\n",
            ],
        ]);

        $headers = @\get_headers($url, false, $context);
        $latencyMs = self::elapsedMs($start);
        if ($headers === false) {
            return ['ok' => false, 'headers' => [], 'latencyMs' => $latencyMs];
        }

        /* @var list<string> $headers */
        return ['ok' => true, 'headers' => $headers, 'latencyMs' => $latencyMs];
    }

    private static function elapsedMs(float $start): float
    {
        return (\microtime(true) - $start) * 1000;
    }
}
