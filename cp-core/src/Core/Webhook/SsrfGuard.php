<?php

declare(strict_types=1);

namespace App\Core\Webhook;

/**
 * Blocks SSRF: only https (http allowed for loopback in test via flag), no private/link-local/metadata IPs.
 */
final class SsrfGuard
{
    /** @var list<string> */
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
        'metadata.google.com',
    ];

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid webhook URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Webhook URL must use HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URL must not contain credentials.');
        }

        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');
        if ($host === '' || in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost')) {
            throw new \InvalidArgumentException('Webhook host is not allowed.');
        }

        $ips = $this->resolveHost($host);
        if ($ips === []) {
            throw new \InvalidArgumentException('Webhook host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new \InvalidArgumentException('Webhook host resolves to a private address.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @gethostbynamel($host);
        if (!\is_array($records) || $records === []) {
            return [];
        }

        return array_values(array_unique($records));
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $ranges = [
                [ip2long('0.0.0.0'), ip2long('0.255.255.255')],
                [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
                [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
                [ip2long('169.254.0.0'), ip2long('169.254.255.255')],
                [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
                [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
                [ip2long('224.0.0.0'), ip2long('255.255.255.255')],
            ];
            foreach ($ranges as [$min, $max]) {
                if ($min !== false && $max !== false && $long >= $min && $long <= $max) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);
            if ($normalized === '::1' || str_starts_with($normalized, 'fe80:') || str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd') || str_starts_with($normalized, '::ffff:')) {
                return true;
            }

            return false;
        }

        return true;
    }
}
