<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class DnsRecordService
{
    private const DOH_RESOLVERS = [
        'Cloudflare' => 'https://cloudflare-dns.com/dns-query',
        'Google' => 'https://dns.google/resolve',
        'Quad9' => 'https://dns.quad9.net:5053/dns-query',
        'OpenDNS' => 'https://doh.opendns.com/dns-query',
    ];

    private const COMMON_SUBDOMAINS = [
        'www', 'mail', 'webmail', 'smtp', 'pop', 'imap', 'ftp', 'ns1', 'ns2',
        'api', 'cdn', 'dev', 'staging', 'test', 'blog', 'shop', 'app', 'm',
        'vpn', 'remote', 'portal', 'admin', 'cpanel', 'autodiscover', 'lyncdiscover',
        'sip', 'vpn', 'git', 'status', 'docs', 'static', 'img', 'media',
    ];

    public function __construct(
        private readonly ProbeClient $probes,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(string $name, int $type): array
    {
        $rows = @dns_get_record($name, $type);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $domain): array
    {
        $a = $this->records($domain, DNS_A);
        $aaaa = $this->records($domain, DNS_AAAA);
        $mx = $this->records($domain, DNS_MX);
        $ns = $this->records($domain, DNS_NS);
        $txt = $this->records($domain, DNS_TXT);
        $cname = $this->records($domain, DNS_CNAME);
        $wwwCname = $this->records('www.'.$domain, DNS_CNAME);
        $soa = $this->records($domain, DNS_SOA);
        $srv = [];
        foreach (['_sip._tcp', '_autodiscover._tcp', '_caldav._tcp'] as $service) {
            $found = $this->records($service.'.'.$domain, DNS_SRV);
            if ($found !== []) {
                $srv[$service] = $found;
            }
        }

        $spf = $this->filterTxt($txt, 'v=spf');
        $dmarc = $this->records('_dmarc.'.$domain, DNS_TXT);
        $dkim = $this->records('default._domainkey.'.$domain, DNS_TXT);
        $mtaSts = $this->records('_mta-sts.'.$domain, DNS_TXT);
        $tlsRpt = $this->records('_smtp._tls.'.$domain, DNS_TXT);
        $bimi = $this->records('default._bimi.'.$domain, DNS_TXT);

        $ptr = null;
        $firstIp = (string) ($a[0]['ip'] ?? '');
        if ($firstIp !== '') {
            $ptr = @gethostbyaddr($firstIp);
        }

        return [
            'domain' => $domain,
            'A' => $this->present($a),
            'AAAA' => $this->present($aaaa),
            'MX' => $this->present($mx),
            'NS' => $this->present($this->enrichNs($ns)),
            'TXT' => $this->present($txt),
            'SPF' => $spf !== [] ? $spf : 'not_found',
            'DMARC' => $this->present($dmarc),
            'DKIM' => $this->present($dkim),
            'CNAME' => $this->present($cname !== [] ? $cname : $wwwCname),
            'SOA' => $this->present($this->normalizeSoa($soa)),
            'PTR' => $ptr ?: 'not_found',
            'SRV' => $srv !== [] ? $srv : 'not_found',
            'MTA-STS' => $this->present($mtaSts),
            'TLS-RPT' => $this->present($tlsRpt),
            'BIMI' => $this->present($bimi),
            'WWW' => [
                'IPv4' => array_column($this->records('www.'.$domain, DNS_A), 'ip'),
                'IPv6' => array_column($this->records('www.'.$domain, DNS_AAAA), 'ipv6'),
            ],
            'Glue' => $this->glue($ns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function typed(string $domain, string $kind): array
    {
        return match ($kind) {
            'mx' => [
                'records' => $this->records($domain, DNS_MX),
                'count' => \count($this->records($domain, DNS_MX)),
            ],
            'cname' => [
                'apex' => $this->records($domain, DNS_CNAME),
                'www' => $this->records('www.'.$domain, DNS_CNAME),
            ],
            'ns' => ['records' => $this->enrichNs($this->records($domain, DNS_NS))],
            'txt' => ['records' => $this->records($domain, DNS_TXT)],
            'aaaa' => ['records' => $this->records($domain, DNS_AAAA)],
            default => $this->lookup($domain),
        };
    }

    /**
     * @param list<string> $domains
     * @return list<array<string, mixed>>
     */
    public function bulk(array $domains): array
    {
        $out = [];
        foreach ($domains as $domain) {
            $a = $this->records($domain, DNS_A);
            $mx = $this->records($domain, DNS_MX);
            $ns = $this->records($domain, DNS_NS);
            $out[] = [
                'domain' => $domain,
                'A' => array_column($a, 'ip'),
                'MX' => array_map(static fn (array $r): string => ($r['pri'] ?? '').' '.($r['target'] ?? ''), $mx),
                'NS' => array_column($ns, 'target'),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(string $left, string $right): array
    {
        $a = $this->lookup($left);
        $b = $this->lookup($right);
        $keys = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'SPF', 'DMARC'];
        $diff = [];
        foreach ($keys as $key) {
            $diff[$key] = [
                'left' => $a[$key] ?? null,
                'right' => $b[$key] ?? null,
                'same' => json_encode($a[$key] ?? null) === json_encode($b[$key] ?? null),
            ];
        }

        return ['left' => $left, 'right' => $right, 'diff' => $diff];
    }

    /**
     * @return array<string, mixed>
     */
    public function reverse(string $ip): array
    {
        $ptr = @gethostbyaddr($ip);

        return [
            'ip' => $ip,
            'ptr' => ($ptr && $ptr !== $ip) ? $ptr : null,
        ];
    }

    /**
     * PTR plus forward confirmation. Full "sites on this IP" lists need a passive-DNS provider.
     *
     * @return array<string, mixed>
     */
    public function reverseIp(string $ip): array
    {
        $lookup = $this->reverse($ip);
        $ptr = $lookup['ptr'];
        $forward = [];
        if (\is_string($ptr) && $ptr !== '') {
            $forward = array_values(array_filter(array_merge(
                array_column($this->records($ptr, DNS_A), 'ip'),
                array_column($this->records($ptr, DNS_AAAA), 'ipv6'),
            )));
        }

        return [
            'ip' => $ip,
            'ptr' => $ptr,
            'forward_ips' => $forward,
            'forward_confirmed' => \in_array($ip, $forward, true),
            'neighbors' => [],
            'note' => 'ptr_only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bimi(string $domain): array
    {
        $host = 'default._bimi.'.$domain;
        $rows = $this->records($host, DNS_TXT);
        $txts = $this->txtStrings($rows);
        $parsed = $txts !== [] ? $this->parseTagged($txts[0]) : [];
        $logo = (string) ($parsed['l'] ?? '');
        $authority = (string) ($parsed['a'] ?? '');
        $logoHttps = $logo !== '' && str_starts_with(strtolower($logo), 'https://');
        $authorityHttps = $authority === '' || str_starts_with(strtolower($authority), 'https://');

        $logoStatus = $logo === '' ? 'missing' : ($logoHttps ? 'present' : 'invalid');
        if ($logoHttps && $this->probes->networkProbesAllowed()) {
            try {
                $head = $this->probes->httpHead($logo, 2);
                $logoStatus = $head['status'] >= 200 && $head['status'] < 400 ? 'valid' : 'warning';
            } catch (\Throwable) {
                $logoStatus = 'warning';
            }
        }

        return [
            'domain' => $domain,
            'host' => $host,
            'records' => $this->present($rows),
            'version' => $parsed['v'] ?? null,
            'logo_url' => $logo !== '' ? $logo : null,
            'authority_url' => $authority !== '' ? $authority : null,
            'logo_https' => $logo === '' ? null : $logoHttps,
            'authority_https' => $authority === '' ? null : $authorityHttps,
            'logo_status' => $logoStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mtaSts(string $domain): array
    {
        $host = '_mta-sts.'.$domain;
        $rows = $this->records($host, DNS_TXT);
        $txts = $this->txtStrings($rows);
        $parsed = $txts !== [] ? $this->parseTagged($txts[0]) : [];
        $policyUrl = 'https://mta-sts.'.$domain.'/.well-known/mta-sts.txt';
        $policy = null;
        $policyStatus = 'not_fetched';

        if ($this->probes->networkProbesAllowed()) {
            try {
                $get = $this->probes->httpGet($policyUrl, 4096);
                $policy = $this->parseMtaStsPolicy($get['body']);
                $policyStatus = $get['status'] === 200 && $policy !== [] ? 'present' : 'missing';
            } catch (\Throwable) {
                $policyStatus = 'missing';
            }
        }

        return [
            'domain' => $domain,
            'host' => $host,
            'records' => $this->present($rows),
            'id' => $parsed['id'] ?? null,
            'version' => $parsed['v'] ?? null,
            'policy_url' => $policyUrl,
            'policy_status' => $policyStatus,
            'mode' => $policy['mode'] ?? null,
            'max_age' => $policy['max_age'] ?? null,
            'mx' => $policy['mx'] ?? [],
            'policy' => $policy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tlsRpt(string $domain): array
    {
        $host = '_smtp._tls.'.$domain;
        $rows = $this->records($host, DNS_TXT);
        $txts = $this->txtStrings($rows);
        $parsed = $txts !== [] ? $this->parseTagged($txts[0]) : [];

        return [
            'domain' => $domain,
            'host' => $host,
            'records' => $this->present($rows),
            'version' => $parsed['v'] ?? null,
            'rua' => $parsed['rua'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dnssec(string $domain): array
    {
        $dnskey = $this->records($domain, defined('DNS_DNSKEY') ? DNS_DNSKEY : DNS_ANY);
        $ds = function_exists('dns_get_record') ? @dns_get_record($domain, defined('DNS_DS') ? DNS_DS : DNS_ANY) : [];
        $ds = \is_array($ds) ? $ds : [];

        $hasDnskey = $this->containsType($dnskey, 'DNSKEY');
        $hasDs = $this->containsType($ds, 'DS');

        return [
            'domain' => $domain,
            'dnskey' => $hasDnskey,
            'ds' => $hasDs,
            'enabled' => $hasDnskey || $hasDs,
            'records' => [
                'DNSKEY' => $dnskey,
                'DS' => $ds,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mailPolicy(string $domain, string $kind, string $selector = 'default'): array
    {
        return match ($kind) {
            'spf' => [
                'records' => $this->filterTxt($this->records($domain, DNS_TXT), 'v=spf'),
            ],
            'dmarc' => [
                'records' => $this->records('_dmarc.'.$domain, DNS_TXT),
            ],
            'dkim' => [
                'selector' => $selector,
                'records' => $this->records($selector.'._domainkey.'.$domain, DNS_TXT),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function ttlView(string $domain): array
    {
        $a = $this->records($domain, DNS_A);
        $ns = $this->records($domain, DNS_NS);
        $soa = $this->normalizeSoa($this->records($domain, DNS_SOA));

        return [
            'A' => $a,
            'NS' => $ns,
            'SOA' => $soa,
            'min_ttl' => $this->minTtl($a),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function responseTime(string $domain): array
    {
        $samples = [];
        for ($i = 0; $i < 3; ++$i) {
            $start = hrtime(true);
            $this->records($domain, DNS_A);
            $samples[] = (int) ((hrtime(true) - $start) / 1_000_000);
        }

        return [
            'samples_ms' => $samples,
            'avg_ms' => (int) round(array_sum($samples) / max(1, \count($samples))),
            'min_ms' => min($samples),
            'max_ms' => max($samples),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function speed(string $domain): array
    {
        $rows = [];
        foreach (self::DOH_RESOLVERS as $name => $endpoint) {
            try {
                $rows[] = ['resolver' => $name] + $this->probes->doh($domain, 'A', $endpoint);
            } catch (\Throwable $e) {
                $rows[] = ['resolver' => $name, 'ok' => false, 'ms' => null, 'error' => $e->getMessage()];
            }
        }
        usort($rows, static fn (array $a, array $b): int => ($a['ms'] ?? 9999) <=> ($b['ms'] ?? 9999));

        return ['resolvers' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function propagation(string $domain): array
    {
        $rows = [];
        foreach (self::DOH_RESOLVERS as $name => $endpoint) {
            try {
                $answer = $this->probes->doh($domain, 'A', $endpoint);
                $ips = [];
                foreach ($answer['answer'] as $item) {
                    if (($item['type'] ?? null) === 1 && isset($item['data'])) {
                        $ips[] = $item['data'];
                    }
                }
                $rows[] = [
                    'resolver' => $name,
                    'ok' => $answer['ok'],
                    'ms' => $answer['ms'],
                    'ips' => array_values(array_unique($ips)),
                ];
            } catch (\Throwable $e) {
                $rows[] = ['resolver' => $name, 'ok' => false, 'ms' => null, 'ips' => [], 'error' => $e->getMessage()];
            }
        }

        $signatures = array_map(static fn (array $r): string => implode(',', $r['ips'] ?? []), $rows);
        $consistent = \count(array_unique($signatures)) <= 1;

        return ['resolvers' => $rows, 'consistent' => $consistent];
    }

    /**
     * @return array<string, mixed>
     */
    public function subdomains(string $domain): array
    {
        $found = [];
        foreach (self::COMMON_SUBDOMAINS as $label) {
            $fqdn = $label.'.'.$domain;
            $a = $this->records($fqdn, DNS_A);
            $aaaa = $this->records($fqdn, DNS_AAAA);
            $cname = $this->records($fqdn, DNS_CNAME);
            if ($a === [] && $aaaa === [] && $cname === []) {
                continue;
            }
            $found[] = [
                'host' => $fqdn,
                'A' => array_column($a, 'ip'),
                'AAAA' => array_column($aaaa, 'ipv6'),
                'CNAME' => array_column($cname, 'target'),
            ];
        }

        return [
            'checked' => \count(self::COMMON_SUBDOMAINS),
            'found' => $found,
        ];
    }

    /**
     * Reports whether AXFR is open. Never returns zone contents.
     *
     * @return array<string, mixed>
     */
    public function zoneTransfer(string $domain): array
    {
        $nameservers = array_column($this->records($domain, DNS_NS), 'target');
        $results = [];

        foreach (array_slice($nameservers, 0, 4) as $ns) {
            $ns = rtrim((string) $ns, '.');
            try {
                $probe = $this->probes->tcp($ns, 53, false);
                $results[] = [
                    'ns' => $ns,
                    'tcp53' => $probe['ok'],
                    'axfr_open' => false,
                    'note' => 'tcp_only',
                ];
            } catch (\Throwable $e) {
                $results[] = ['ns' => $ns, 'tcp53' => false, 'axfr_open' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'nameservers' => $nameservers,
            'checks' => $results,
            'safe' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function enrichNs(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $ns = rtrim((string) ($record['target'] ?? ''), '.');
            $out[] = [
                'NS' => $ns,
                'IPv4' => array_column($this->records($ns, DNS_A), 'ip'),
                'IPv6' => array_column($this->records($ns, DNS_AAAA), 'ipv6'),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $ns
     * @return list<array<string, string>>
     */
    private function glue(array $ns): array
    {
        $out = [];
        foreach ($ns as $record) {
            $name = rtrim((string) ($record['target'] ?? ''), '.');
            $hasA = $this->records($name, DNS_A) !== [];
            $out[] = ['NS' => $name, 'status' => $hasA ? 'present' : 'missing'];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $soa
     * @return list<array<string, mixed>>
     */
    private function normalizeSoa(array $soa): array
    {
        if ($soa === []) {
            return [];
        }

        $row = $soa[0];

        return [[
            'primary_ns' => $row['mname'] ?? null,
            'responsible_email' => isset($row['rname']) ? preg_replace('/\./', '@', (string) $row['rname'], 1) : null,
            'serial' => $row['serial'] ?? null,
            'refresh' => $row['refresh'] ?? null,
            'retry' => $row['retry'] ?? null,
            'expire' => $row['expire'] ?? null,
            'minimum_ttl' => $row['minimum-ttl'] ?? null,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $txt
     * @return list<string>
     */
    private function filterTxt(array $txt, string $prefix): array
    {
        $out = [];
        foreach ($txt as $row) {
            $value = (string) ($row['txt'] ?? '');
            if (str_starts_with(strtolower($value), strtolower($prefix))) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function containsType(array $rows, string $type): bool
    {
        foreach ($rows as $row) {
            if (strtoupper((string) ($row['type'] ?? '')) === strtoupper($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function minTtl(array $rows): ?int
    {
        $ttls = [];
        foreach ($rows as $row) {
            if (isset($row['ttl'])) {
                $ttls[] = (int) $row['ttl'];
            }
        }

        return $ttls === [] ? null : min($ttls);
    }

    /**
     * @param list<mixed>|string $value
     */
    private function present(array|string $value): array|string
    {
        return $value === [] ? 'not_found' : $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function txtStrings(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['txt']) && \is_string($row['txt']) && $row['txt'] !== '') {
                $out[] = $row['txt'];
                continue;
            }
            if (isset($row['entries']) && \is_array($row['entries'])) {
                $out[] = implode('', array_map('strval', $row['entries']));
            }
        }

        return array_values(array_filter($out, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<string, string>
     */
    private function parseTagged(string $txt): array
    {
        $parts = [];
        foreach (preg_split('/\s*;\s*/', $txt) ?: [] as $chunk) {
            if (!str_contains($chunk, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $chunk, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMtaStsPolicy(string $body): array
    {
        $policy = ['mx' => []];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);
            if ($key === 'mx') {
                $policy['mx'][] = $value;
                continue;
            }
            if ($key === 'max_age') {
                $policy['max_age'] = (int) $value;
                continue;
            }
            $policy[$key] = $value;
        }

        return $policy;
    }
}
