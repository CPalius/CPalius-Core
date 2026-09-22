<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class DomainAnalysisService
{
    public function __construct(
        private readonly DnsRecordService $dns,
        private readonly WhoisService $whois,
        private readonly BlacklistService $blacklist,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyse(string $domain): array
    {
        $dns = $this->dns->lookup($domain);
        $whois = $this->whois->domain($domain);
        $blacklist = ['listed_count' => 0, 'listed' => []];
        $ip = $this->firstIp($dns['A'] ?? null);
        if ($ip !== null) {
            try {
                $blacklist = $this->blacklist->check($ip);
            } catch (\Throwable) {
            }
        }

        $score = 0;
        $notes = [];
        if (($dns['A'] ?? 'not_found') !== 'not_found') {
            $score += 15;
        } else {
            $notes[] = 'missing_a';
        }
        if (($dns['MX'] ?? 'not_found') !== 'not_found') {
            $score += 15;
        }
        if (($dns['SPF'] ?? 'not_found') !== 'not_found') {
            $score += 15;
        } else {
            $notes[] = 'missing_spf';
        }
        if (($dns['DMARC'] ?? 'not_found') !== 'not_found') {
            $score += 15;
        } else {
            $notes[] = 'missing_dmarc';
        }
        if (($dns['NS'] ?? 'not_found') !== 'not_found') {
            $score += 10;
        }
        if (!empty($whois['creation_date'])) {
            $score += 15;
        }
        if (($blacklist['listed_count'] ?? 0) === 0) {
            $score += 15;
        } else {
            $notes[] = 'blacklisted';
        }

        return [
            'domain' => $domain,
            'score' => $score,
            'notes' => $notes,
            'dns' => $dns,
            'whois' => $whois,
            'blacklist' => $blacklist,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(string $domain): array
    {
        $whois = $this->whois->domain($domain);
        $dns = $this->dns->lookup($domain);

        return [
            'domain' => $domain,
            'whois' => $whois,
            'current_dns' => [
                'A' => $dns['A'] ?? null,
                'NS' => $dns['NS'] ?? null,
                'MX' => $dns['MX'] ?? null,
            ],
            'note' => 'snapshot_only',
        ];
    }

    private function firstIp(mixed $records): ?string
    {
        if (!\is_array($records)) {
            return null;
        }
        foreach ($records as $row) {
            if (\is_array($row) && isset($row['ip'])) {
                return (string) $row['ip'];
            }
        }

        return null;
    }
}
