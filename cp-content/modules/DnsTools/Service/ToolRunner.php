<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use App\Core\Settings\SettingsRegistry;
use Modules\DnsTools\Catalog\ToolCatalog;
use Modules\DnsTools\Catalog\ToolDefinition;
use Modules\DnsTools\Security\QueryValidator;
use Symfony\Component\HttpFoundation\Request;

final class ToolRunner
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ToolRegistry $registry,
        private readonly QueryValidator $validator,
        private readonly DnsRecordService $dns,
        private readonly WhoisService $whois,
        private readonly SslService $ssl,
        private readonly HttpProbeService $http,
        private readonly NetworkProbeService $network,
        private readonly MailAnalysisService $mail,
        private readonly GeneratorService $generator,
        private readonly IpUtilityService $ip,
        private readonly BlacklistService $blacklist,
        private readonly DomainAnalysisService $analysis,
        private readonly ResultCache $cache,
        private readonly SettingsRegistry $settings,
        private readonly ProbeClient $probes,
        private readonly InboxService $inbox,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(string $slug, array $input, Request $request): array
    {
        $tool = $this->catalog->get($slug);
        if (!$tool instanceof ToolDefinition) {
            throw new \InvalidArgumentException('dnstools.error.unknown_tool');
        }
        if ((string) $this->settings->get('dnstools.enabled', '1') !== '1') {
            throw new \RuntimeException('dnstools.error.disabled');
        }
        if ($this->registry->get($slug) === null) {
            throw new \InvalidArgumentException('dnstools.error.unknown_tool');
        }
        if ($tool->networkProbe && !$this->probes->networkProbesAllowed()) {
            throw new \RuntimeException('dnstools.error.probes_disabled');
        }

        $target = $this->cacheKey($tool, $input, $request);
        if ($target !== null) {
            $cached = $this->cache->get($slug, $target);
            if ($cached !== null) {
                return $cached + ['cached' => true];
            }
        }

        $result = $this->dispatch($tool, $input, $request);
        $result['tool'] = $slug;
        $result['cached'] = false;

        if ($target !== null && ($result['ok'] ?? true) !== false) {
            $this->cache->set($slug, $target, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function dispatch(ToolDefinition $tool, array $input, Request $request): array
    {
        $q = trim((string) ($input['q'] ?? ''));

        return match ($tool->slug) {
            'dns-lookup' => $this->dns->lookup($this->requireDomain($q)),
            'mx-lookup' => $this->dns->typed($this->requireDomain($q), 'mx'),
            'cname-lookup' => $this->dns->typed($this->requireDomain($q), 'cname'),
            'ns-lookup' => $this->dns->typed($this->requireDomain($q), 'ns'),
            'txt-lookup' => $this->dns->typed($this->requireDomain($q), 'txt'),
            'aaaa-lookup' => $this->dns->typed($this->requireDomain($q), 'aaaa'),
            'reverse-dns' => $this->dns->reverse($this->requireIp($q)),
            'bulk-dns' => ['rows' => $this->dns->bulk($this->validator->domainList($q, $this->maxBulk()))],
            'subdomain-finder' => $this->dns->subdomains($this->requireDomain($q)),
            'dns-compare' => $this->dns->compare(
                $this->requireDomain((string) ($input['left'] ?? $q)),
                $this->requireDomain((string) ($input['right'] ?? '')),
            ),
            'dnssec' => $this->dns->dnssec($this->requireDomain($q)),
            'dns-leak' => $this->leak($request),
            'zone-transfer' => $this->dns->zoneTransfer($this->requireDomain($q)),
            'spf-checker' => $this->dns->mailPolicy($this->requireDomain($q), 'spf'),
            'dmarc-checker' => $this->dns->mailPolicy($this->requireDomain($q), 'dmarc'),
            'dkim-checker' => $this->dns->mailPolicy(
                $this->requireDomain($q),
                'dkim',
                $this->validator->dkimSelector((string) ($input['selector'] ?? 'default')),
            ),
            'bimi-checker' => $this->dns->bimi($this->requireDomain($q)),
            'mta-sts-checker' => $this->dns->mtaSts($this->requireDomain($q)),
            'tls-rpt-checker' => $this->dns->tlsRpt($this->requireDomain($q)),
            'dns-cache' => $this->dns->ttlView($this->requireDomain($q)),
            'dns-response-time' => $this->dns->responseTime($this->requireDomain($q)),
            'dns-speed' => $this->dns->speed($this->requireDomain($q)),
            'dns-propagation' => $this->dns->propagation($this->requireDomain($q)),
            'dns-generator' => $this->generator->dns($this->requireDomain((string) ($input['domain'] ?? $q)), $input),
            'dmarc-generator' => $this->generator->dmarc($this->requireDomain((string) ($input['domain'] ?? $q)), $input),
            'dkim-generator' => $this->generator->dkim($this->requireDomain((string) ($input['domain'] ?? $q)), $input),
            'bimi-generator' => $this->generator->bimi($this->requireDomain((string) ($input['domain'] ?? $q)), $input),
            'domain-analysis' => $this->analysis->analyse($this->requireDomain($q)),
            'domain-age' => $this->sliceWhois($this->whois->domain($this->requireDomain($q)), ['creation_date', 'age_days', 'age_years', 'registrar']),
            'domain-expiry' => $this->sliceWhois($this->whois->domain($this->requireDomain($q)), ['expiry_date', 'days_until_expiry', 'registrar', 'status']),
            'whois' => $this->whois->domain($this->requireDomain($q)),
            'domain-history' => $this->analysis->history($this->requireDomain($q)),
            'smtp-tester' => $this->network->smtp($this->requireHost($q)),
            'email-header' => $this->mail->headers($q),
            'email-security' => $this->mail->emailSecurity($this->requireEmail($q)),
            'spam-score' => $this->inbox->handle($input, $request),
            'email-deliverability' => $this->mail->deliverability($this->requireEmail($q)),
            'ip-lookup' => $this->network->ipLookup($this->clientOrIp($q, $request)),
            'cidr-calculator' => $this->ip->cidr($this->requireCidr($q)),
            'reverse-ip' => $this->dns->reverseIp($this->requireIp($q)),
            'ip-blacklist' => $this->blacklist->check($this->requireIp($q !== '' ? $q : (string) $request->getClientIp())),
            'ping' => $this->network->ping($this->requireHost($q)),
            'ip-whois' => $this->whois->ip($this->requireIp($q)),
            'port-checker' => $this->network->ports(
                $this->requireHost($q),
                $this->validator->ports((string) ($input['ports'] ?? ''), NetworkProbeService::COMMON_PORTS),
            ),
            'mac-lookup' => $this->ip->mac($this->requireMac($q)),
            'asn-lookup' => $this->whois->asn($this->requireAsn($q)),
            'traceroute' => $this->network->traceroute($this->requireHost($q)),
            'latency' => $this->network->latency($this->requireHost($q)),
            'bandwidth' => ['chunk_bytes' => 262144, 'note' => 'client_measure'],
            'ssl-checker' => $this->ssl->check($this->requireHost($q)),
            'http-headers' => $this->http->headers($this->requireUrl($q)),
            'url-redirect' => $this->http->redirects($this->requireUrl($q)),
            'ipv4-to-ipv6' => $this->ip->ipv4ToIpv6($this->requireIp($q)),
            'ipv6-compress' => $this->ip->compress($this->requireIp($q)),
            default => throw new \InvalidArgumentException('dnstools.error.unknown_tool'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function leak(Request $request): array
    {
        $ip = (string) $request->getClientIp();
        $ptr = null;
        if ($ip !== '') {
            $rev = $this->dns->reverse($ip);
            $ptr = $rev['ptr'] ?? null;
        }

        return [
            'client_ip' => $ip,
            'ptr' => $ptr,
            'resolver_view' => 'server_side',
        ];
    }

    /**
     * @param array<string, mixed> $whois
     * @param list<string>         $keys
     * @return array<string, mixed>
     */
    private function sliceWhois(array $whois, array $keys): array
    {
        $out = ['query' => $whois['query'] ?? null];
        foreach ($keys as $key) {
            $out[$key] = $whois[$key] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function cacheKey(ToolDefinition $tool, array $input, Request $request): ?string
    {
        if (\in_array($tool->slug, ['email-header', 'spam-score', 'dns-generator', 'dmarc-generator', 'dkim-generator', 'bimi-generator', 'bandwidth', 'dns-leak'], true)) {
            return null;
        }

        $parts = [
            (string) ($input['q'] ?? ''),
            (string) ($input['left'] ?? ''),
            (string) ($input['right'] ?? ''),
            (string) ($input['selector'] ?? ''),
            (string) ($input['domain'] ?? ''),
            (string) $request->getClientIp(),
        ];

        return strtolower(implode('|', $parts));
    }

    private function requireDomain(string $raw): string
    {
        $domain = $this->validator->domain($raw);
        if ($domain === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_domain');
        }

        return $domain;
    }

    private function requireIp(string $raw): string
    {
        $ip = $this->validator->ip($raw);
        if ($ip === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_ip');
        }

        return $ip;
    }

    /**
     * @return array{ip: string, prefix: int}
     */
    private function requireCidr(string $raw): array
    {
        $cidr = $this->validator->cidr($raw);
        if ($cidr === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_cidr');
        }

        return $cidr;
    }

    private function requireHost(string $raw): string
    {
        $host = $this->validator->host($raw);
        if ($host === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_host');
        }

        return $host;
    }

    private function requireUrl(string $raw): string
    {
        $url = $this->validator->url($raw);
        if ($url === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_url');
        }

        return $url;
    }

    private function requireEmail(string $raw): string
    {
        $email = $this->validator->email($raw);
        if ($email === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_email');
        }

        return $email;
    }

    private function requireMac(string $raw): string
    {
        $mac = $this->validator->mac($raw);
        if ($mac === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_mac');
        }

        return $mac;
    }

    private function requireAsn(string $raw): int
    {
        $asn = $this->validator->asn($raw);
        if ($asn === null) {
            throw new \InvalidArgumentException('dnstools.error.invalid_asn');
        }

        return $asn;
    }

    private function clientOrIp(string $raw, Request $request): string
    {
        if (trim($raw) === '') {
            $ip = (string) $request->getClientIp();
            if ($this->validator->ip($ip) !== null) {
                return $ip;
            }
        }

        return $this->requireIp($raw);
    }

    private function maxBulk(): int
    {
        return max(1, min(25, (int) $this->settings->get('dnstools.max_bulk', 10)));
    }
}
