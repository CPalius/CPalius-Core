<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Modules\DnsTools\Security\SsrfGuard;

final class NetworkProbeService
{
    public const COMMON_PORTS = [21, 22, 25, 53, 80, 110, 143, 443, 465, 587, 993, 995, 8080, 8443];

    public function __construct(
        private readonly ProbeClient $probes,
        private readonly SsrfGuard $ssrf,
        private readonly DnsRecordService $dns,
        private readonly WhoisService $whois,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(string $host): array
    {
        $ips = $this->ssrf->resolvePublic($host);
        if ($ips === null) {
            throw new \InvalidArgumentException('dnstools.error.private_target');
        }

        $samples = [];
        foreach ([80, 443] as $port) {
            try {
                $samples[] = $this->probes->tcp($host, $port, false) + ['port' => $port];
            } catch (\Throwable $e) {
                $samples[] = ['ok' => false, 'ms' => null, 'port' => $port, 'error' => $e->getMessage()];
            }
        }

        return [
            'host' => $host,
            'ips' => $ips,
            'method' => 'tcp_connect',
            'samples' => $samples,
        ];
    }

    /**
     * @param list<int> $ports
     * @return array<string, mixed>
     */
    public function ports(string $host, array $ports): array
    {
        if (!$this->probes->portChecksAllowed()) {
            throw new \RuntimeException('dnstools.error.ports_disabled');
        }

        $this->ssrf->assertPublicHost($host);
        $scan = $ports !== [] ? $ports : self::COMMON_PORTS;
        $scan = array_values(array_intersect($scan, self::COMMON_PORTS));
        $results = [];

        foreach (array_slice($scan, 0, 15) as $port) {
            try {
                $probe = $this->probes->tcp($host, $port, false);
                $results[] = ['port' => $port, 'open' => $probe['ok'], 'ms' => $probe['ms']];
            } catch (\Throwable $e) {
                $results[] = ['port' => $port, 'open' => false, 'ms' => null, 'error' => $e->getMessage()];
            }
        }

        return ['host' => $host, 'results' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    public function latency(string $host): array
    {
        $samples = [];
        for ($i = 0; $i < 4; ++$i) {
            try {
                $probe = $this->probes->tcp($host, 443, false);
                $samples[] = $probe['ok'] ? $probe['ms'] : null;
            } catch (\Throwable) {
                $samples[] = null;
            }
        }
        $ok = array_values(array_filter($samples, static fn (?int $ms): bool => $ms !== null));

        return [
            'host' => $host,
            'samples_ms' => $samples,
            'avg_ms' => $ok === [] ? null : (int) round(array_sum($ok) / \count($ok)),
            'loss' => $ok === [] ? 100 : (int) round((4 - \count($ok)) / 4 * 100),
        ];
    }

    /**
     * TCP reachability summary. Full ICMP traceroute needs raw sockets and is not used.
     *
     * @return array<string, mixed>
     */
    public function traceroute(string $host): array
    {
        $ips = $this->ssrf->resolvePublic($host) ?? [];
        $ptr = [];
        foreach ($ips as $ip) {
            $rev = $this->dns->reverse($ip);
            $ptr[$ip] = $rev['ptr'];
        }

        $probe = null;
        try {
            $probe = $this->probes->tcp($host, 443, false);
        } catch (\Throwable $e) {
            $probe = ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'host' => $host,
            'ips' => $ips,
            'ptr' => $ptr,
            'destination' => $probe,
            'method' => 'tcp_443',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ipLookup(string $ip): array
    {
        $this->ssrf->assertPublicHost($ip);
        $rev = $this->dns->reverse($ip);
        $whois = $this->whois->ip($ip);

        return [
            'ip' => $ip,
            'ptr' => $rev['ptr'],
            'whois' => $whois,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function smtp(string $host): array
    {
        if (!$this->probes->networkProbesAllowed()) {
            throw new \RuntimeException('dnstools.error.probes_disabled');
        }

        $target = $host;
        if (filter_var($host, FILTER_VALIDATE_IP) === false && !str_contains($host, '.')) {
            throw new \InvalidArgumentException('dnstools.error.invalid_host');
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $mx = $this->dns->records($host, DNS_MX);
            if ($mx !== []) {
                usort($mx, static fn (array $a, array $b): int => ((int) ($a['pri'] ?? 10)) <=> ((int) ($b['pri'] ?? 10)));
                $target = rtrim((string) ($mx[0]['target'] ?? $host), '.');
            }
        }

        $ports = [];
        foreach ([25, 465, 587] as $port) {
            try {
                $ports[] = $this->probes->tcp($target, $port, true) + ['port' => $port];
            } catch (\Throwable $e) {
                $ports[] = ['ok' => false, 'port' => $port, 'error' => $e->getMessage()];
            }
        }

        return [
            'input' => $host,
            'target' => $target,
            'ports' => $ports,
        ];
    }
}
