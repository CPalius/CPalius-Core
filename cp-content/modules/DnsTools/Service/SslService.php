<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class SslService
{
    public function __construct(
        private readonly ProbeClient $probes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $host, int $port = 443): array
    {
        $peer = $this->probes->tlsPeer($host, $port);
        $parsed = $peer['peer'];
        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $now = time();
        $daysLeft = $validTo !== null ? (int) floor(($validTo - $now) / 86400) : null;

        $sans = [];
        $alt = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        foreach (explode(',', $alt) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $sans[] = substr($part, 4);
            }
        }

        $status = 'invalid';
        if ($validFrom !== null && $validTo !== null && $now >= $validFrom && $now <= $validTo) {
            $status = $daysLeft !== null && $daysLeft <= 21 ? 'warning' : 'valid';
        }

        return [
            'host' => $host,
            'port' => $port,
            'status' => $status,
            'issuer' => $this->name($parsed['issuer'] ?? []),
            'subject' => $this->name($parsed['subject'] ?? []),
            'valid_from' => $validFrom !== null ? date('Y-m-d H:i:s', $validFrom) : null,
            'valid_to' => $validTo !== null ? date('Y-m-d H:i:s', $validTo) : null,
            'days_left' => $daysLeft,
            'sans' => $sans,
            'signature' => $parsed['signatureTypeSN'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $dn
     */
    private function name(array $dn): string
    {
        if (isset($dn['CN'])) {
            return (string) $dn['CN'];
        }
        if (isset($dn['O'])) {
            return (string) $dn['O'];
        }

        return $dn === [] ? '' : implode(', ', array_map(static fn (mixed $v): string => (string) $v, $dn));
    }
}
