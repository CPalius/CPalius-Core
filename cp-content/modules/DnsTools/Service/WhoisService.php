<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class WhoisService
{
    private const IANA = 'whois.iana.org';

    public function __construct(
        private readonly ProbeClient $probes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function domain(string $domain): array
    {
        $raw = $this->queryChain($domain);
        $parsed = $this->parse($raw, $domain);

        return $parsed + ['raw' => $this->truncate($raw)];
    }

    /**
     * @return array<string, mixed>
     */
    public function ip(string $ip): array
    {
        $server = str_contains($ip, ':') ? 'whois.ripe.net' : 'whois.arin.net';
        try {
            $raw = $this->probes->whois($server, $ip);
            if ($this->referral($raw) !== null) {
                try {
                    $raw = $this->probes->whois($this->referral($raw) ?? $server, $ip);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
            $response = $this->probes->httpGet('https://rdap.org/ip/'.rawurlencode($ip), 20000);
            $raw = (string) ($response['body'] ?? '');
            if ($raw === '') {
                throw new \RuntimeException('dnstools.error.whois_unavailable');
            }
        }

        return $this->parse($raw, $ip) + ['raw' => $this->truncate($raw), 'ip' => $ip];
    }

    /**
     * @return array<string, mixed>
     */
    public function asn(int $asn): array
    {
        $raw = $this->probes->whois('whois.radb.net', 'AS'.$asn);

        return $this->parse($raw, 'AS'.$asn) + ['raw' => $this->truncate($raw), 'asn' => $asn];
    }

    private function queryChain(string $domain): string
    {
        try {
            $iana = $this->probes->whois(self::IANA, $domain);
            $server = $this->referral($iana) ?? $this->tldServer($domain);
            if ($server === null) {
                return $iana !== '' ? $iana : $this->rdapText($domain);
            }

            try {
                $raw = $this->probes->whois($server, $domain);

                return $raw !== '' ? $raw : $this->rdapText($domain);
            } catch (\Throwable) {
                return $iana !== '' ? $iana : $this->rdapText($domain);
            }
        } catch (\Throwable) {
            return $this->rdapText($domain);
        }
    }

    private function rdapText(string $domain): string
    {
        $url = match (strtolower((string) substr(strrchr($domain, '.'), 1))) {
            'com', 'net' => 'https://rdap.verisign.com/com/v1/domain/'.rawurlencode($domain),
            'org' => 'https://rdap.publicinterestregistry.org/rdap/domain/'.rawurlencode($domain),
            default => 'https://rdap.org/domain/'.rawurlencode($domain),
        };
        $response = $this->probes->httpGet($url, 20000);
        if (($response['status'] ?? 0) >= 300 && ($response['status'] ?? 0) < 400) {
            $location = (string) ($response['headers']['location'] ?? '');
            if ($location !== '' && preg_match('#^https://#i', $location) === 1) {
                $response = $this->probes->httpGet($location, 20000);
            }
        }
        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (!\is_array($json)) {
            throw new \RuntimeException('dnstools.error.whois_unavailable');
        }

        $lines = ['RDAP: '.$domain];
        foreach ($json['events'] ?? [] as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $action = (string) ($event['eventAction'] ?? '');
            $date = (string) ($event['eventDate'] ?? '');
            $label = match ($action) {
                'registration' => 'Creation Date',
                'expiration' => 'Expiry Date',
                'last changed' => 'Updated Date',
                default => '',
            };
            if ($label !== '' && $date !== '') {
                $lines[] = $label.': '.$date;
            }
        }
        foreach ($json['nameservers'] ?? [] as $ns) {
            $name = \is_array($ns) ? (string) ($ns['ldhName'] ?? '') : '';
            if ($name !== '') {
                $lines[] = 'Name Server: '.$name;
            }
        }
        foreach ($json['status'] ?? [] as $status) {
            if (\is_string($status) && $status !== '') {
                $lines[] = 'Status: '.$status;
            }
        }

        return implode("\n", $lines);
    }

    private function referral(string $raw): ?string
    {
        if (preg_match('/(?:whois(?:\s+server)?|refer):\s*([a-z0-9.-]+\.[a-z]{2,})/i', $raw, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function tldServer(string $domain): ?string
    {
        $tld = strtolower((string) substr(strrchr($domain, '.'), 1));
        $map = [
            'com' => 'whois.verisign-grs.com',
            'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org',
            'io' => 'whois.nic.io',
            'dev' => 'whois.nic.google',
            'app' => 'whois.nic.google',
            'tr' => 'whois.trabis.gov.tr',
            'uk' => 'whois.nic.uk',
            'de' => 'whois.denic.de',
            'eu' => 'whois.eu',
            'info' => 'whois.afilias.net',
        ];

        return $map[$tld] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $raw, string $subject): array
    {
        $result = [
            'query' => $subject,
            'creation_date' => null,
            'updated_date' => null,
            'expiry_date' => null,
            'registrar' => null,
            'registrant_org' => null,
            'registrant_country' => null,
            'name_servers' => [],
            'status' => [],
            'dnssec' => null,
            'org' => null,
            'country' => null,
            'netname' => null,
        ];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($key);
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            if (str_contains($k, 'creat') || $k === 'registered') {
                $result['creation_date'] ??= $this->date($value);
            } elseif (str_contains($k, 'updat') || str_contains($k, 'modified')) {
                $result['updated_date'] ??= $this->date($value);
            } elseif (str_contains($k, 'expir')) {
                $result['expiry_date'] ??= $this->date($value);
            } elseif ($k === 'registrar' || $k === 'sponsoring registrar') {
                $result['registrar'] ??= $value;
            } elseif (str_contains($k, 'registrant organiz') || $k === 'org' || $k === 'organisation') {
                $result['registrant_org'] ??= $value;
                $result['org'] ??= $value;
            } elseif (str_contains($k, 'registrant country') || $k === 'country') {
                $result['registrant_country'] ??= $value;
                $result['country'] ??= $value;
            } elseif (str_contains($k, 'name server') || $k === 'nserver') {
                $ns = strtolower(rtrim($value, '.'));
                if ($ns !== '' && !\in_array($ns, $result['name_servers'], true)) {
                    $result['name_servers'][] = $ns;
                }
            } elseif (str_contains($k, 'status')) {
                $status = $this->statusToken($value);
                if ($status !== '' && !\in_array($status, $result['status'], true)) {
                    $result['status'][] = $status;
                }
            } elseif ($k === 'dnssec') {
                $result['dnssec'] = strtolower($value);
            } elseif ($k === 'netname') {
                $result['netname'] = $value;
            }
        }

        if ($result['creation_date'] !== null) {
            $created = new \DateTimeImmutable($result['creation_date']);
            $now = new \DateTimeImmutable('today');
            $result['age_days'] = (int) $created->diff($now)->days;
            $result['age_years'] = (int) $created->diff($now)->y;
        }
        if ($result['expiry_date'] !== null) {
            $expiry = new \DateTimeImmutable($result['expiry_date']);
            $result['days_until_expiry'] = (int) (new \DateTimeImmutable('today'))->diff($expiry)->format('%r%a');
        }

        return $result;
    }

    private function date(string $value): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
            return $m[1];
        }
        $ts = strtotime($value);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function truncate(string $raw): string
    {
        $clean = trim($raw);

        return strlen($clean) > 12000 ? substr($clean, 0, 12000)."\n…" : $clean;
    }

    private function statusToken(string $value): string
    {
        $value = trim(preg_replace('#https?://\S+#i', '', $value) ?? $value);
        $value = trim($value, " \t,;");

        return $value;
    }
}
