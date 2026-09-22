<?php

declare(strict_types=1);

namespace Modules\DnsTools\Security;

/**
 * Blocks probes against loopback, link-local, private, multicast and metadata ranges.
 */
final class SsrfGuard
{
    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if ($this->isBlockedSpecial($ip)) {
            return false;
        }

        return true;
    }

    /**
     * Resolves a host and rejects it when any address is not publicly routable.
     *
     * @return list<string>|null
     */
    public function resolvePublic(string $host): ?array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? [$host] : null;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!\is_array($records) || $records === []) {
            return null;
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (!$this->isPublicIp($ip)) {
                return null;
            }
            $ips[$ip] = $ip;
        }

        return $ips === [] ? null : array_values($ips);
    }

    public function assertPublicHost(string $host): void
    {
        if ($this->resolvePublic($host) === null) {
            throw new \InvalidArgumentException('dnstools.error.private_target');
        }
    }

    private function isBlockedSpecial(string $ip): bool
    {
        $blocked = [
            '0.0.0.0',
            '255.255.255.255',
            '169.254.169.254',
            '::',
            '::1',
        ];

        if (\in_array($ip, $blocked, true)) {
            return true;
        }

        if (str_starts_with($ip, '169.254.') || str_starts_with(strtolower($ip), 'fe80:')) {
            return true;
        }

        if (str_starts_with($ip, '100.')) {
            $second = (int) explode('.', $ip)[1];
            if ($second >= 64 && $second <= 127) {
                return true;
            }
        }

        return false;
    }
}
