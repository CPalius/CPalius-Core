<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps diagnostic warnings to forum search queries. Never posts a result for the user.
 */
final class CommunityHints
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array{key: string, query: string, label: string}>
     */
    public function for(string $slug, array $result): array
    {
        $hints = [];
        $blob = strtolower(json_encode($result, JSON_UNESCAPED_SLASHES) ?: '');

        if ($this->missing($result['records'] ?? $result['DMARC'] ?? null) || str_contains($blob, '"dmarc":"not_found"')) {
            if (\in_array($slug, ['dmarc-checker', 'dns-lookup', 'email-security', 'domain-analysis'], true)) {
                $hints[] = $this->hint('missing_dmarc', 'DMARC kaydı yok nasıl eklenir');
            }
        }
        if (preg_match('/p\s*=\s*none/', $blob) === 1) {
            $hints[] = $this->hint('dmarc_none', 'DMARC p=none nasıl düzeltilir');
        }
        if ($this->missing($result['records'] ?? $result['SPF'] ?? null) && \in_array($slug, ['spf-checker', 'dns-lookup', 'email-security'], true)) {
            $hints[] = $this->hint('missing_spf', 'SPF kaydı yok Too many DNS lookups');
        }
        if (substr_count($blob, 'include:') > 10) {
            $hints[] = $this->hint('spf_lookups', 'SPF Too many DNS lookups');
        }
        if ($this->missing($result['records'] ?? $result['BIMI'] ?? null) && \in_array($slug, ['bimi-checker', 'dns-lookup'], true)) {
            $hints[] = $this->hint('missing_bimi', 'BIMI kaydı nasıl eklenir');
        }
        if (($result['logo_url'] ?? null) && ($result['logo_https'] ?? true) === false) {
            $hints[] = $this->hint('bimi_http', 'BIMI logo HTTPS olmalı');
        }
        if ($this->missing($result['records'] ?? $result['MTA-STS'] ?? null) || ($result['policy_status'] ?? '') === 'missing') {
            if (\in_array($slug, ['mta-sts-checker', 'dns-lookup'], true)) {
                $hints[] = $this->hint('missing_mta_sts', 'MTA-STS kaydı ve politika dosyası');
            }
        }
        if (($result['mode'] ?? '') === 'none' && $slug === 'mta-sts-checker') {
            $hints[] = $this->hint('mta_sts_none', 'MTA-STS mode none');
        }
        if ($this->missing($result['records'] ?? $result['TLS-RPT'] ?? null) && \in_array($slug, ['tls-rpt-checker', 'dns-lookup'], true)) {
            $hints[] = $this->hint('missing_tls_rpt', 'TLS-RPT kaydı nasıl eklenir');
        }
        if ((int) ($result['listed_count'] ?? 0) > 0) {
            $hints[] = $this->hint('blacklisted', 'IP blacklist temizleme');
        }
        $days = $result['days_until_expiry'] ?? null;
        if (\is_numeric($days) && (int) $days < 30) {
            $hints[] = $this->hint('expiry', 'domain süresi dolmak üzere');
        }
        if (($result['axfr_open'] ?? false) === true) {
            $hints[] = $this->hint('axfr', 'zone transfer açık nasıl kapatılır');
        }
        if ($slug === 'reverse-ip' && empty($result['ptr'])) {
            $hints[] = $this->hint('missing_ptr', 'PTR kaydı yok reverse DNS');
        }
        if (\in_array($slug, ['spam-score', 'email-deliverability'], true) && \in_array((string) ($result['grade'] ?? ''), ['warning', 'poor'], true)) {
            $hints[] = $this->hint('low_spam_score', 'mail spam skoru düşük nasıl yükseltilir');
        }

        return $hints;
    }

    /**
     * @return array{key: string, query: string, label: string}
     */
    private function hint(string $key, string $query): array
    {
        return [
            'key' => $key,
            'query' => $query,
            'label' => $this->translator->trans('dnstools.forum.'.$key),
        ];
    }

    private function missing(mixed $value): bool
    {
        return $value === 'not_found' || $value === [] || $value === null;
    }
}
