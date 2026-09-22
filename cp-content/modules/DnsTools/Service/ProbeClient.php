<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;
use Modules\DnsTools\Security\SsrfGuard;

/**
 * Bounded TCP / TLS / HTTP client used by every outbound probe.
 */
final class ProbeClient
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SsrfGuard $ssrf,
    ) {
    }

    public function timeout(): int
    {
        return max(2, min(12, (int) $this->settings->get('dnstools.probe_timeout', 5)));
    }

    public function networkProbesAllowed(): bool
    {
        return (string) $this->settings->get('dnstools.allow_network_probes', '1') === '1';
    }

    public function portChecksAllowed(): bool
    {
        return $this->networkProbesAllowed()
            && (string) $this->settings->get('dnstools.allow_port_check', '1') === '1';
    }

    /**
     * @return array{ok: bool, ms: int, banner: string, error: ?string}
     */
    public function tcp(string $host, int $port, bool $readBanner = true): array
    {
        $this->ssrf->assertPublicHost($host);
        $timeout = $this->timeout();
        $start = hrtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->socketHost($host), $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);

        if (!\is_resource($fp)) {
            return ['ok' => false, 'ms' => $ms, 'banner' => '', 'error' => $errstr !== '' ? $errstr : 'connection failed'];
        }

        stream_set_timeout($fp, $timeout);
        $banner = '';
        if ($readBanner) {
            $banner = (string) @fgets($fp, 512);
        }
        fclose($fp);

        return ['ok' => true, 'ms' => $ms, 'banner' => trim($banner), 'error' => null];
    }

    /**
     * @return array{ok: bool, peer: array<string, mixed>, raw: string}
     */
    public function tlsPeer(string $host, int $port = 443): array
    {
        $this->ssrf->assertPublicHost($host);
        $timeout = $this->timeout();
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $fp = @stream_socket_client(
            sprintf('ssl://%s:%d', $this->socketHost($host), $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!\is_resource($fp)) {
            throw new \RuntimeException($errstr !== '' ? $errstr : 'TLS handshake failed');
        }

        $params = stream_context_get_params($fp);
        fclose($fp);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            throw new \RuntimeException('Peer certificate was not captured.');
        }

        $parsed = openssl_x509_parse($cert) ?: [];
        openssl_x509_export($cert, $raw);

        return ['ok' => true, 'peer' => $parsed, 'raw' => (string) $raw];
    }

    /**
     * @return array{status: int, headers: array<string, string>, url: string, redirects: list<array{url: string, status: int}>}
     */
    public function httpHead(string $url, int $maxRedirects = 5): array
    {
        $current = $url;
        $redirects = [];
        $last = ['status' => 0, 'headers' => [], 'url' => $url];

        for ($i = 0; $i <= $maxRedirects; ++$i) {
            $parts = parse_url($current);
            if (!\is_array($parts) || empty($parts['host'])) {
                break;
            }
            $host = (string) $parts['host'];
            $this->ssrf->assertPublicHost($host);
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
            $path = (string) ($parts['path'] ?? '/');
            if (isset($parts['query'])) {
                $path .= '?'.$parts['query'];
            }

            $transport = $scheme === 'http' ? 'tcp' : 'ssl';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            $fp = @stream_socket_client(
                sprintf('%s://%s:%d', $transport, $this->socketHost($host), $port),
                $errno,
                $errstr,
                $this->timeout(),
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (!\is_resource($fp)) {
                throw new \RuntimeException($errstr !== '' ? $errstr : 'HTTP probe failed');
            }

            stream_set_timeout($fp, $this->timeout());
            $request = "HEAD {$path} HTTP/1.1\r\nHost: {$host}\r\nUser-Agent: CPalius-DnsTools/1.0\r\nConnection: close\r\nAccept: */*\r\n\r\n";
            fwrite($fp, $request);
            $raw = stream_get_contents($fp) ?: '';
            fclose($fp);

            [$status, $headers] = $this->parseResponse($raw);
            $last = ['status' => $status, 'headers' => $headers, 'url' => $current];

            $location = $headers['location'] ?? null;
            if ($status >= 300 && $status < 400 && \is_string($location) && $location !== '' && $i < $maxRedirects) {
                $redirects[] = ['url' => $current, 'status' => $status];
                $current = $this->resolveLocation($current, $location);
                continue;
            }

            break;
        }

        return [
            'status' => $last['status'],
            'headers' => $last['headers'],
            'url' => $last['url'],
            'redirects' => $redirects,
        ];
    }

    /**
     * Bounded GET used for published policy files (MTA-STS). Private hosts stay blocked.
     *
     * @return array{status: int, headers: array<string, string>, body: string, url: string}
     */
    public function httpGet(string $url, int $maxBytes = 4096): array
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('dnstools.error.invalid_url');
        }

        $host = (string) $parts['host'];
        $this->ssrf->assertPublicHost($host);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('dnstools.error.invalid_url');
        }

        $port = (int) ($parts['port'] ?? 443);
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $fp = @stream_socket_client(
            sprintf('ssl://%s:%d', $this->socketHost($host), $port),
            $errno,
            $errstr,
            $this->timeout(),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!\is_resource($fp)) {
            throw new \RuntimeException($errstr !== '' ? $errstr : 'HTTP probe failed');
        }

        stream_set_timeout($fp, $this->timeout());
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUser-Agent: CPalius-DnsTools/1.0\r\nConnection: close\r\nAccept: text/plain,*/*\r\n\r\n");
        $raw = stream_get_contents($fp) ?: '';
        fclose($fp);

        $split = explode("\r\n\r\n", $raw, 2);
        [$status, $headers] = $this->parseResponse($split[0]);
        $body = substr($split[1] ?? '', 0, max(256, min(16384, $maxBytes)));

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'url' => $url,
        ];
    }

    public function whois(string $server, string $query): string
    {
        $this->ssrf->assertPublicHost($server);
        $timeout = $this->timeout();
        $fp = @stream_socket_client(
            sprintf('tcp://%s:43', $this->socketHost($server)),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );
        if (!\is_resource($fp)) {
            throw new \RuntimeException($errstr !== '' ? $errstr : 'WHOIS connection failed');
        }

        stream_set_timeout($fp, $timeout);
        fwrite($fp, $query."\r\n");
        $body = stream_get_contents($fp) ?: '';
        fclose($fp);

        return $body;
    }

    public function doh(string $name, string $type, string $endpoint): array
    {
        $payload = json_encode(['ok' => false]);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout(),
                'header' => "Accept: application/dns-json\r\nUser-Agent: CPalius-DnsTools/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $url = $endpoint.'?name='.rawurlencode($name).'&type='.rawurlencode($type);
        $start = hrtime(true);
        $raw = @file_get_contents($url, false, $context);
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);

        $decoded = \is_string($raw) ? json_decode($raw, true) : null;

        return [
            'ms' => $ms,
            'ok' => \is_array($decoded),
            'answer' => \is_array($decoded) ? ($decoded['Answer'] ?? []) : [],
            'status' => \is_array($decoded) ? ($decoded['Status'] ?? null) : null,
        ];
    }

    private function socketHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$host.']' : $host;
    }

    /**
     * @return array{0: int, 1: array<string, string>}
     */
    private function parseResponse(string $raw): array
    {
        $headerBlock = explode("\r\n\r\n", $raw, 2)[0];
        $lines = preg_split('/\r\n/', $headerBlock) ?: [];
        $status = 0;
        $headers = [];

        foreach ($lines as $i => $line) {
            if ($i === 0 && preg_match('/HTTP\/\d(?:\.\d)?\s+(\d{3})/', $line, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (\count($parts) !== 2) {
                continue;
            }
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return [$status, $headers];
    }

    private function resolveLocation(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($current) ?: [];
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.$location;
    }
}
