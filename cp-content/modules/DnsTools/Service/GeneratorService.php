<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class GeneratorService
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function dns(string $domain, array $input): array
    {
        $rows = [];
        $ttl = max(60, min(86400, (int) ($input['ttl'] ?? 3600)));

        if (!empty($input['a'])) {
            $rows[] = $this->row('@', 'A', $ttl, (string) $input['a']);
            $rows[] = $this->row('www', 'CNAME', $ttl, $domain.'.');
        }
        if (!empty($input['aaaa'])) {
            $rows[] = $this->row('@', 'AAAA', $ttl, (string) $input['aaaa']);
        }
        if (!empty($input['mx_server'])) {
            $priority = max(0, min(100, (int) ($input['mx_priority'] ?? 10)));
            $rows[] = $this->row('@', 'MX', $ttl, $priority.' '.rtrim((string) $input['mx_server'], '.').'.');
        }
        if (!empty($input['ns1'])) {
            $rows[] = $this->row('@', 'NS', $ttl, rtrim((string) $input['ns1'], '.').'.');
        }
        if (!empty($input['ns2'])) {
            $rows[] = $this->row('@', 'NS', $ttl, rtrim((string) $input['ns2'], '.').'.');
        }
        if (!empty($input['spf'])) {
            $rows[] = $this->row('@', 'TXT', $ttl, '"'.addcslashes((string) $input['spf'], '"').'"');
        } elseif (!empty($input['mx_server'])) {
            $rows[] = $this->row('@', 'TXT', $ttl, '"v=spf1 mx a -all"');
        }
        if (!empty($input['dmarc'])) {
            $rows[] = $this->row('_dmarc', 'TXT', $ttl, '"'.addcslashes((string) $input['dmarc'], '"').'"');
        }
        if (!empty($input['dkim_selector']) && !empty($input['dkim_value'])) {
            $selector = preg_replace('/[^a-z0-9._-]/i', '', (string) $input['dkim_selector']) ?: 'default';
            $rows[] = $this->row($selector.'._domainkey', 'TXT', $ttl, '"'.addcslashes((string) $input['dkim_value'], '"').'"');
        }
        if (!empty($input['cname_name']) && !empty($input['cname_target'])) {
            $rows[] = $this->row((string) $input['cname_name'], 'CNAME', $ttl, rtrim((string) $input['cname_target'], '.').'.');
        }

        $bind = [];
        $cloudflare = [];
        foreach ($rows as $row) {
            $bind[] = sprintf('%s %d IN %s %s', $row['name'], $row['ttl'], $row['type'], $row['content']);
            $cloudflare[] = sprintf('%s,%s,%s,%d,false', $row['name'] === '@' ? $domain : $row['name'].'.'.$domain, $row['type'], $row['content'], $row['ttl']);
        }

        return [
            'domain' => $domain,
            'records' => $rows,
            'bind' => implode("\n", $bind),
            'cloudflare_csv' => "Name,Type,Content,TTL,Proxy\n".implode("\n", $cloudflare),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function dmarc(string $domain, array $input): array
    {
        $policy = \in_array($input['policy'] ?? 'none', ['none', 'quarantine', 'reject'], true) ? (string) $input['policy'] : 'none';
        $rua = trim((string) ($input['rua'] ?? ''));
        $parts = ['v=DMARC1', 'p='.$policy];
        if ($rua !== '' && filter_var($rua, FILTER_VALIDATE_EMAIL)) {
            $parts[] = 'rua=mailto:'.$rua;
        }
        $parts[] = 'adkim=s';
        $parts[] = 'aspf=s';
        $record = implode('; ', $parts);

        return [
            'domain' => $domain,
            'host' => '_dmarc.'.$domain,
            'type' => 'TXT',
            'value' => $record,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function dkim(string $domain, array $input): array
    {
        $selector = preg_replace('/[^a-z0-9._-]/i', '', (string) ($input['selector'] ?? 'default')) ?: 'default';
        $key = trim((string) ($input['public_key'] ?? ''));
        $record = 'v=DKIM1; k=rsa; p='.$key;

        return [
            'domain' => $domain,
            'host' => $selector.'._domainkey.'.$domain,
            'type' => 'TXT',
            'value' => $record,
            'selector' => $selector,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function bimi(string $domain, array $input): array
    {
        $logo = trim((string) ($input['logo_url'] ?? ''));
        $authority = trim((string) ($input['authority_url'] ?? ''));
        if ($logo === '' || !str_starts_with(strtolower($logo), 'https://')) {
            throw new \InvalidArgumentException('dnstools.error.invalid_url');
        }
        if ($authority !== '' && !str_starts_with(strtolower($authority), 'https://')) {
            throw new \InvalidArgumentException('dnstools.error.invalid_url');
        }

        $parts = ['v=BIMI1', 'l='.$logo];
        if ($authority !== '') {
            $parts[] = 'a='.$authority;
        }

        return [
            'domain' => $domain,
            'host' => 'default._bimi.'.$domain,
            'type' => 'TXT',
            'value' => implode('; ', $parts),
            'logo_url' => $logo,
            'authority_url' => $authority !== '' ? $authority : null,
        ];
    }

    /**
     * @return array{name: string, type: string, ttl: int, content: string}
     */
    private function row(string $name, string $type, int $ttl, string $content): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'ttl' => $ttl,
            'content' => $content,
        ];
    }
}
