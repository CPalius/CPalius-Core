<?php

declare(strict_types=1);

namespace Modules\DnsTools\Security;

/**
 * Normalises and rejects untrusted probe targets before any network work starts.
 */
final class QueryValidator
{
    public function domain(string $raw): ?string
    {
        $value = $this->stripScheme($raw);
        $value = strtolower(rtrim($value, '.'));

        if ($value === '' || strlen($value) > 253 || str_contains($value, '/') || str_contains($value, ' ')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
            return null;
        }

        return $value;
    }

    public function ip(string $raw): ?string
    {
        $value = trim($raw);
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $value = substr($value, 1, -1);
        }

        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
        $ip = filter_var($value, FILTER_VALIDATE_IP, $flags);

        return $ip !== false ? $ip : null;
    }

    /**
     * @return array{ip: string, prefix: int}|null
     */
    public function cidr(string $raw): ?array
    {
        $value = trim($raw);
        $prefix = null;
        if (preg_match('#^(.+)/(\d{1,3})$#', $value, $matches) === 1) {
            $value = $matches[1];
            $prefix = (int) $matches[2];
        }

        $ip = $this->ip($value);
        if ($ip === null) {
            return null;
        }

        $max = str_contains($ip, ':') ? 128 : 32;
        if ($prefix === null) {
            $prefix = $max;
        }
        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        return ['ip' => $ip, 'prefix' => $prefix];
    }

    public function host(string $raw): ?string
    {
        $ip = $this->ip($raw);
        if ($ip !== null) {
            return $ip;
        }

        return $this->domain($raw);
    }

    public function url(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$this->stripScheme($value);
        }

        $parts = parse_url($value);
        if (!\is_array($parts) || empty($parts['host']) || !\in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = $this->host((string) $parts['host']);
        if ($host === null) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public function email(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        $email = filter_var($value, FILTER_VALIDATE_EMAIL);

        return $email !== false ? $email : null;
    }

    public function mac(string $raw): ?string
    {
        $value = strtoupper(trim($raw));
        $value = str_replace(['-', '.', ' '], ':', $value);

        if (preg_match('/^[0-9A-F]{12}$/', $value) === 1) {
            $value = implode(':', str_split($value, 2));
        }

        if (preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public function asn(string $raw): ?int
    {
        $value = strtoupper(trim($raw));
        $value = preg_replace('/^AS/', '', $value) ?? $value;

        if (preg_match('/^\d{1,10}$/', $value) !== 1) {
            return null;
        }

        $asn = (int) $value;

        return $asn > 0 && $asn <= 4294967295 ? $asn : null;
    }

    /**
     * @return list<string>
     */
    public function domainList(string $raw, int $max): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $domains = [];

        foreach ($parts as $part) {
            $domain = $this->domain((string) $part);
            if ($domain === null || isset($domains[$domain])) {
                continue;
            }
            $domains[$domain] = $domain;
            if (\count($domains) >= $max) {
                break;
            }
        }

        return array_values($domains);
    }

    /**
     * @return list<int>
     */
    public function ports(string $raw, array $allowlist): array
    {
        $allow = array_fill_keys(array_map(static fn (int $p): int => $p, $allowlist), true);
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $ports = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d{1,5}$/', (string) $part) !== 1) {
                continue;
            }
            $port = (int) $part;
            if (!isset($allow[$port]) || isset($ports[$port])) {
                continue;
            }
            $ports[$port] = $port;
        }

        return array_values($ports);
    }

    public function dkimSelector(string $raw): string
    {
        $value = strtolower(trim($raw));
        if ($value === '' || preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,61}[a-z0-9])?$/', $value) !== 1) {
            return 'default';
        }

        return $value;
    }

    private function stripScheme(string $raw): string
    {
        $value = trim($raw);
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#^www\.#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];
        $value = explode('?', $value, 2)[0];
        $value = explode('#', $value, 2)[0];

        return trim($value);
    }
}
