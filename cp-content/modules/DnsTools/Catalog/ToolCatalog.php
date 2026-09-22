<?php

declare(strict_types=1);

namespace Modules\DnsTools\Catalog;

/**
 * Single source of truth for every public tool: routing, SEO, forms and sitemap.
 */
final class ToolCatalog
{
    /**
     * @var array<string, ToolDefinition>|null
     */
    private ?array $indexed = null;

    /**
     * @return list<ToolDefinition>
     */
    public function all(): array
    {
        return array_values($this->index());
    }

    public function get(string $slug): ?ToolDefinition
    {
        return $this->index()[$slug] ?? null;
    }

    /**
     * @return list<ToolDefinition>
     */
    public function byCategory(string $category): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ToolDefinition $tool): bool => $tool->category === $category,
        ));
    }

    /**
     * @return list<ToolDefinition>
     */
    public function featured(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ToolDefinition $tool): bool => $tool->featured,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function categories(): array
    {
        return [
            'query' => 'dnstools.category.query',
            'security' => 'dnstools.category.security',
            'performance' => 'dnstools.category.performance',
            'management' => 'dnstools.category.management',
            'domain' => 'dnstools.category.domain',
            'mail' => 'dnstools.category.mail',
            'network' => 'dnstools.category.network',
            'web' => 'dnstools.category.web',
            'ipv6' => 'dnstools.category.ipv6',
        ];
    }

    /**
     * @return array<string, ToolDefinition>
     */
    private function index(): array
    {
        if ($this->indexed !== null) {
            return $this->indexed;
        }

        $tools = [
            new ToolDefinition('dns-lookup', 'query', 'bi-search', 'domain', featured: true),
            new ToolDefinition('mx-lookup', 'query', 'bi-envelope', 'domain', featured: true),
            new ToolDefinition('cname-lookup', 'query', 'bi-link', 'domain'),
            new ToolDefinition('ns-lookup', 'query', 'bi-hdd-network', 'domain'),
            new ToolDefinition('txt-lookup', 'query', 'bi-file-text', 'domain'),
            new ToolDefinition('aaaa-lookup', 'query', 'bi-router', 'domain'),
            new ToolDefinition('reverse-dns', 'query', 'bi-arrow-left-right', 'ip'),
            new ToolDefinition('bulk-dns', 'query', 'bi-list-check', 'text'),
            new ToolDefinition('subdomain-finder', 'query', 'bi-diagram-3', 'domain', isNew: true),
            new ToolDefinition('dns-compare', 'query', 'bi-arrow-left-right', 'dual'),

            new ToolDefinition('dnssec', 'security', 'bi-shield-check', 'domain', featured: true),
            new ToolDefinition('dns-leak', 'security', 'bi-shield-exclamation', 'none', isNew: true),
            new ToolDefinition('zone-transfer', 'security', 'bi-shield-lock', 'domain', isNew: true, networkProbe: true),
            new ToolDefinition('spf-checker', 'security', 'bi-envelope-check', 'domain'),
            new ToolDefinition('dmarc-checker', 'security', 'bi-shield-check', 'domain'),
            new ToolDefinition('dkim-checker', 'security', 'bi-key', 'domain'),
            new ToolDefinition('bimi-checker', 'security', 'bi-image', 'domain', isNew: true),
            new ToolDefinition('mta-sts-checker', 'security', 'bi-lock', 'domain', isNew: true),
            new ToolDefinition('tls-rpt-checker', 'security', 'bi-file-earmark-lock', 'domain', isNew: true),

            new ToolDefinition('dns-cache', 'performance', 'bi-database', 'domain'),
            new ToolDefinition('dns-response-time', 'performance', 'bi-stopwatch', 'domain'),
            new ToolDefinition('dns-speed', 'performance', 'bi-speedometer2', 'domain', isNew: true),
            new ToolDefinition('dns-propagation', 'performance', 'bi-globe2', 'domain', featured: true),

            new ToolDefinition('dns-generator', 'management', 'bi-tools', 'generate', featured: true),
            new ToolDefinition('dmarc-generator', 'management', 'bi-shield-plus', 'generate'),
            new ToolDefinition('dkim-generator', 'management', 'bi-key-fill', 'generate'),
            new ToolDefinition('bimi-generator', 'management', 'bi-image-fill', 'generate', isNew: true),

            new ToolDefinition('domain-analysis', 'domain', 'bi-graph-up', 'domain', featured: true),
            new ToolDefinition('domain-age', 'domain', 'bi-calendar-event', 'domain', isNew: true),
            new ToolDefinition('domain-expiry', 'domain', 'bi-calendar-x', 'domain', isNew: true),
            new ToolDefinition('whois', 'domain', 'bi-info-circle', 'domain', featured: true),
            new ToolDefinition('domain-history', 'domain', 'bi-clock-history', 'domain'),

            new ToolDefinition('smtp-tester', 'mail', 'bi-envelope-check', 'host', featured: true, networkProbe: true),
            new ToolDefinition('email-header', 'mail', 'bi-envelope-paper', 'text'),
            new ToolDefinition('email-security', 'mail', 'bi-shield-check', 'email'),
            new ToolDefinition('spam-score', 'mail', 'bi-ui-checks', 'inbox', featured: true, isNew: true),
            new ToolDefinition('email-deliverability', 'mail', 'bi-mailbox', 'email', isNew: true),

            new ToolDefinition('ip-lookup', 'network', 'bi-search', 'ip', featured: true),
            new ToolDefinition('cidr-calculator', 'network', 'bi-calculator', 'cidr', isNew: true),
            new ToolDefinition('reverse-ip', 'network', 'bi-hdd-stack', 'ip', isNew: true),
            new ToolDefinition('ip-blacklist', 'network', 'bi-shield-x', 'ip'),
            new ToolDefinition('ping', 'network', 'bi-arrow-repeat', 'host', networkProbe: true),
            new ToolDefinition('ip-whois', 'network', 'bi-info-circle', 'ip'),
            new ToolDefinition('port-checker', 'network', 'bi-wifi', 'ports', featured: true, networkProbe: true),
            new ToolDefinition('mac-lookup', 'network', 'bi-ethernet', 'mac'),
            new ToolDefinition('asn-lookup', 'network', 'bi-diagram-3', 'asn'),
            new ToolDefinition('traceroute', 'network', 'bi-signpost-2', 'host', isNew: true, networkProbe: true),
            new ToolDefinition('latency', 'network', 'bi-stopwatch', 'host', isNew: true, networkProbe: true),
            new ToolDefinition('bandwidth', 'network', 'bi-speedometer2', 'none'),

            new ToolDefinition('ssl-checker', 'web', 'bi-shield-lock', 'host', featured: true, networkProbe: true),
            new ToolDefinition('http-headers', 'web', 'bi-shield-check', 'url', networkProbe: true),
            new ToolDefinition('url-redirect', 'web', 'bi-arrow-right-circle', 'url', networkProbe: true),

            new ToolDefinition('ipv4-to-ipv6', 'ipv6', 'bi-shuffle', 'ip'),
            new ToolDefinition('ipv6-compress', 'ipv6', 'bi-arrow-down-square', 'ip'),
        ];

        $this->indexed = [];
        foreach ($tools as $tool) {
            $this->indexed[$tool->slug] = $tool;
        }

        return $this->indexed;
    }
}
