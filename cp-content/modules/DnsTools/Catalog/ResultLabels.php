<?php

declare(strict_types=1);

namespace Modules\DnsTools\Catalog;

/**
 * Result table keys and known enum values. The front renderer looks these up
 * in dnstools.field.* / dnstools.value.* so every tool shares one catalogue.
 */
final class ResultLabels
{
    /**
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'query', 'domain', 'host', 'ip', 'ipv4', 'ipv6', 'ptr', 'email',
            'creation_date', 'updated_date', 'expiry_date', 'age_days', 'age_years',
            'days_until_expiry', 'days_left', 'registrar', 'registrant_org',
            'registrant_country', 'name_servers', 'nameservers', 'status', 'dnssec',
            'org', 'country', 'netname', 'cached', 'raw',
            'records', 'count', 'apex', 'www', 'rows', 'left', 'right', 'diff', 'same',
            'A', 'AAAA', 'MX', 'NS', 'TXT', 'SPF', 'DMARC', 'DKIM', 'BIMI', 'CNAME', 'SOA',
            'PTR', 'SRV', 'MTA-STS', 'TLS-RPT', 'WWW', 'Glue', 'IPv4', 'IPv6',
            'dnskey', 'ds', 'enabled', 'selector', 'min_ttl', 'ttl', 'type', 'class',
            'pri', 'target', 'txt', 'content', 'name', 'value',
            'primary_ns', 'responsible_email', 'serial', 'refresh', 'retry', 'expire', 'minimum_ttl',
            'samples_ms', 'avg_ms', 'min_ms', 'max_ms', 'ms', 'loss',
            'resolvers', 'resolver', 'ok', 'error', 'answer', 'ips', 'consistent',
            'checked', 'found', 'checks', 'ns', 'tcp53', 'axfr_open', 'note', 'safe',
            'score', 'notes', 'dns', 'whois', 'blacklist', 'current_dns',
            'mapped', 'nat64', 'dotted', 'input', 'compressed', 'expanded',
            'mac', 'oui', 'vendor', 'unicast', 'locally_administered',
            'bind', 'cloudflare_csv', 'listed', 'clean', 'listed_count',
            'from', 'to', 'subject', 'date', 'message_id', 'received', 'headers',
            'grade', 'address', 'inbox_ready', 'expires_in', 'alignment', 'sending_ip',
            'points', 'note', 'token',
            'security_present', 'security_missing', 'start', 'final', 'hops', 'chain',
            'url', 'method', 'samples', 'port', 'open', 'results', 'destination',
            'target', 'ports', 'banner', 'issuer', 'valid_from', 'valid_to', 'sans',
            'signature', 'chunk_bytes', 'client_ip', 'resolver_view',
            'network', 'broadcast', 'netmask', 'wildcard', 'prefix', 'version',
            'first_host', 'last_host', 'total_addresses', 'usable_hosts',
            'forward_ips', 'forward_confirmed', 'neighbors',
            'logo_url', 'authority_url', 'logo_https', 'authority_https', 'logo_status',
            'policy_url', 'policy_status', 'mode', 'max_age', 'policy', 'rua', 'id',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            'empty', 'yes', 'no', 'not_found', 'unsigned', 'signed',
            'present', 'missing', 'valid', 'warning', 'invalid',
            'tcp_only', 'snapshot_only', 'client_measure', 'server_side',
            'tcp_connect', 'tcp_443', 'missing_a', 'missing_spf', 'missing_dmarc',
            'blacklisted', 'cached_yes', 'cached_no',
            'ptr_only', 'ipv6_no_broadcast', 'not_fetched',
            'waiting', 'scored', 'expired', 'paste_only', 'excellent', 'good', 'poor',
            'pass', 'fail', 'softfail', 'inbox_not_configured', 'auth_from_headers', 'dns_only',
        ];
    }
}
