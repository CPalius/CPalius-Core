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
     * The probe URL is operator-supplied, so the scheme has to be pinned before it
     * reaches curl. Without this, curl happily speaks file://, dict:// or gopher://,
     * and gopher:// in particular turns a header probe into "send arbitrary bytes to
     * any reachable internal port". Probing loopback stays allowed on purpose — that
     * is what a Varnish/PageSpeed check is for.
     */
    public static function isProbeableUrl(string $url): bool
    {
        $scheme = \parse_url(\trim($url), \PHP_URL_SCHEME);

        return \is_string($scheme) && \in_array(\strtolower($scheme), ['http', 'https'], true);
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

        $target = self::probeTarget($url);
        if ($target === null) {
            return ['ok' => false, 'headers' => [], 'latencyMs' => self::elapsedMs($start)];
        }

        if (\function_exists('curl_init')) {
            return self::fetchWithCurl($target, $timeout, $start);
        }

        return self::fetchWithStream($target, $timeout, $start);
    }

    /**
     * The single point where an operator-supplied URL becomes a probe target, and so
     * the single point where the scheme is decided. Everything below it may assume
     * http(s); nothing above it may.
     *
     * @psalm-taint-escape ssrf
     */
    private static function probeTarget(string $url): ?string
    {
        return self::isProbeableUrl($url) ? $url : null;
    }

    /**
     * Only ever reached through fetchHeaders(), which rejects any scheme other than
     * http/https; CURLOPT_PROTOCOLS below pins it a second time.
     *
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
            // Second gate behind isProbeableUrl(): even a future caller that skips the
            // scheme check cannot make this handle speak anything but HTTP(S).
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
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
     * Same guard as fetchWithCurl(): fetchHeaders() has already pinned the scheme to
     * http/https, which is also all the http:// stream wrapper would honour here.
     *
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
