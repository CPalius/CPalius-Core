<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

use Modules\DnsTools\Security\SsrfGuard;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Transparent 0–10 score. No SpamAssassin, no shell, no outbound mail.
 * Authentication-Results are trusted when present; otherwise only DNS existence is claimed.
 */
final class SpamScoreService
{
    public function __construct(
        private readonly MailAnalysisService $mail,
        private readonly DnsRecordService $dns,
        private readonly BlacklistService $blacklist,
        private readonly SsrfGuard $ssrf,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function score(string $raw): array
    {
        $raw = substr($raw, 0, 50000);
        $parsed = $this->mail->headers($raw);
        $headers = \is_array($parsed['headers'] ?? null) ? $parsed['headers'] : [];
        $from = (string) ($parsed['from'] ?? '');
        $fromDomain = $this->emailDomain($from);
        $auth = $this->authentication($headers);
        $sendingIp = $this->sendingIp(\is_array($parsed['received'] ?? null) ? $parsed['received'] : []);
        $ptr = null;
        $forward = false;
        $listed = ['ip' => $sendingIp, 'listed' => [], 'clean' => [], 'listed_count' => 0];

        if ($sendingIp !== null) {
            $rev = $this->dns->reverse($sendingIp);
            $ptr = $rev['ptr'] ?? null;
            if (\is_string($ptr) && $ptr !== '') {
                $fwd = $this->dns->reverseIp($sendingIp);
                $forward = ($fwd['forward_confirmed'] ?? false) === true;
            }
            try {
                $listed = $this->blacklist->check($sendingIp);
            } catch (\Throwable) {
            }
        }

        $dnsSpf = $fromDomain !== '' ? $this->dns->mailPolicy($fromDomain, 'spf') : ['records' => []];
        $dnsDmarc = $fromDomain !== '' ? $this->dns->mailPolicy($fromDomain, 'dmarc') : ['records' => []];
        $dkimSelector = $this->headerValue($headers, 'dkim-signature', 's');
        $dkimDomain = $this->headerValue($headers, 'dkim-signature', 'd') ?? $fromDomain;
        $dnsDkim = ($dkimDomain !== '' && $dkimSelector !== null)
            ? $this->dns->mailPolicy($dkimDomain, 'dkim', $dkimSelector)
            : ['records' => []];

        $body = $this->plainBody($raw);
        $checks = [];
        $score = 10.0;

        $this->add($checks, $score, 'spf', $this->authPoints($auth['spf'], $dnsSpf['records'] ?? [], 2.0, 1.0));
        $this->add($checks, $score, 'dkim', $this->authPoints($auth['dkim'], $dnsDkim['records'] ?? [], 2.0, 1.0));
        $this->add($checks, $score, 'dmarc', $this->authPoints($auth['dmarc'], $dnsDmarc['records'] ?? [], 1.5, 0.7));

        $aligned = $this->aligned($fromDomain, $dkimDomain, $auth);
        $this->add($checks, $score, 'alignment', $aligned ? [0.0, 'pass'] : [-1.0, 'fail']);

        if ($sendingIp === null) {
            $this->add($checks, $score, 'sending_ip', [-0.8, 'missing']);
        } elseif ((int) ($listed['listed_count'] ?? 0) > 0) {
            $this->add($checks, $score, 'blacklist', [-3.0, 'blacklisted']);
        } else {
            $this->add($checks, $score, 'blacklist', [0.0, 'clean']);
        }

        if ($sendingIp !== null && ($ptr === null || $ptr === '')) {
            $this->add($checks, $score, 'ptr', [-1.0, 'missing']);
        } elseif ($sendingIp !== null && !$forward) {
            $this->add($checks, $score, 'ptr', [-0.5, 'warning']);
        } elseif ($sendingIp !== null) {
            $this->add($checks, $score, 'ptr', [0.0, 'valid']);
        }

        if (trim((string) ($parsed['message_id'] ?? '')) === '') {
            $this->add($checks, $score, 'message_id', [-0.5, 'missing']);
        }
        if (trim((string) ($parsed['date'] ?? '')) === '') {
            $this->add($checks, $score, 'date', [-0.3, 'missing']);
        }

        $subject = trim((string) ($parsed['subject'] ?? ''));
        if ($subject === '' || ($subject === strtoupper($subject) && mb_strlen($subject) > 8)) {
            $this->add($checks, $score, 'subject', [-0.3, 'warning']);
        }

        if ($body === '') {
            $this->add($checks, $score, 'body', [-0.5, 'missing']);
        } elseif (preg_match_all('/https?:\/\//i', $body) > 5) {
            $this->add($checks, $score, 'body', [-0.4, 'warning']);
        }

        $score = max(0.0, min(10.0, round($score, 1)));
        $grade = $this->grade($score);
        $advice = $this->advice($checks);

        $result = [
            'status' => 'scored',
            'score' => $score,
            'grade' => $grade,
            'from' => $from !== '' ? $from : null,
            'to' => $parsed['to'] ?? null,
            'subject' => $subject !== '' ? $subject : null,
            'date' => $parsed['date'] ?? null,
            'message_id' => $parsed['message_id'] ?? null,
            'spf' => $auth['spf'] ?? ($this->hasRecords($dnsSpf['records'] ?? []) ? 'present' : 'missing'),
            'dkim' => $auth['dkim'] ?? ($this->hasRecords($dnsDkim['records'] ?? []) ? 'present' : 'missing'),
            'dmarc' => $auth['dmarc'] ?? ($this->hasRecords($dnsDmarc['records'] ?? []) ? 'present' : 'missing'),
            'alignment' => $aligned ? 'pass' : 'fail',
            'sending_ip' => $sendingIp,
            'ptr' => $ptr,
            'forward_confirmed' => $forward,
            'blacklist' => $listed,
            'listed_count' => (int) ($listed['listed_count'] ?? 0),
            'checks' => $checks,
            'advice' => $advice,
        ];
        $result['share_text'] = $this->shareText($result);

        return $result;
    }

    /**
     * @param list<array{name: string, result: string, points: float}> $checks
     * @return list<array{level: string, title: string, text: string}>
     */
    private function advice(array $checks): array
    {
        $by = [];
        foreach ($checks as $check) {
            $by[$check['name']] = $check['result'];
        }

        $keys = [
            'spf' => ['missing' => 'spf_missing', 'fail' => 'spf_fail', 'softfail' => 'spf_fail', 'present' => 'spf_present', 'pass' => 'spf_pass'],
            'dkim' => ['missing' => 'dkim_missing', 'fail' => 'dkim_fail', 'present' => 'dkim_present', 'pass' => 'dkim_pass'],
            'dmarc' => ['missing' => 'dmarc_missing', 'fail' => 'dmarc_fail', 'present' => 'dmarc_present', 'pass' => 'dmarc_pass'],
            'alignment' => ['fail' => 'alignment_fail', 'pass' => 'alignment_pass'],
            'blacklist' => ['blacklisted' => 'blacklist_hit', 'clean' => 'blacklist_clean'],
            'ptr' => ['missing' => 'ptr_missing', 'warning' => 'ptr_warning', 'valid' => 'ptr_valid'],
            'subject' => ['warning' => 'subject_warning'],
            'body' => ['missing' => 'body_missing', 'warning' => 'body_links'],
            'message_id' => ['missing' => 'message_id_missing'],
        ];
        $bad = ['missing', 'fail', 'blacklisted'];
        $warn = ['present', 'softfail', 'warning'];
        $items = [];
        foreach ($keys as $name => $map) {
            $result = (string) ($by[$name] ?? '');
            $key = $map[$result] ?? null;
            if ($key === null) {
                continue;
            }
            $level = \in_array($result, $bad, true) ? 'bad' : (\in_array($result, $warn, true) ? 'warn' : 'ok');
            $items[] = [
                'level' => $level,
                'title' => $this->translator->trans('dnstools.spam.advice.'.$key.'.title'),
                'text' => $this->translator->trans('dnstools.spam.advice.'.$key.'.text'),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function shareText(array $result): string
    {
        $lines = [
            $this->translator->trans('dnstools.spam.share.score', [
                'score' => (string) $result['score'],
                'grade' => $this->translator->trans('dnstools.value.'.(string) $result['grade']),
            ]),
            $this->translator->trans('dnstools.field.from').': '.(string) ($result['from'] ?? ''),
            $this->translator->trans('dnstools.field.to').': '.(string) ($result['to'] ?? ''),
            $this->translator->trans('dnstools.field.subject').': '.(string) ($result['subject'] ?? ''),
            $this->translator->trans('dnstools.field.sending_ip').': '.(string) ($result['sending_ip'] ?? ''),
            '',
            $this->translator->trans('dnstools.spam.advice_title'),
        ];
        foreach ($result['advice'] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $lines[] = '- '.(string) ($item['title'] ?? '').': '.(string) ($item['text'] ?? '');
        }

        return mb_substr(implode("\n", $lines), 0, 4000);
    }

    /**
     * @param list<array{name: string, result: string, points: float}> $checks
     */
    private function add(array &$checks, float &$score, string $name, array $row): void
    {
        $points = (float) $row[0];
        $result = (string) $row[1];
        $score += $points;
        $checks[] = ['name' => $name, 'result' => $result, 'points' => $points];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function authPoints(?string $headerResult, mixed $records, float $failCost, float $dnsOnlyCost): array
    {
        $headerResult = $headerResult !== null ? strtolower($headerResult) : null;
        if (\in_array($headerResult, ['pass', 'valid'], true)) {
            return [0.0, 'pass'];
        }
        if (\in_array($headerResult, ['fail', 'softfail', 'permerror', 'temperror', 'invalid'], true)) {
            return [-$failCost, $headerResult === 'softfail' ? 'softfail' : 'fail'];
        }
        if ($this->hasRecords($records)) {
            return [-$dnsOnlyCost, 'present'];
        }

        return [-$failCost, 'missing'];
    }

    /**
     * @param array<string, string> $headers
     * @return array{spf: ?string, dkim: ?string, dmarc: ?string}
     */
    private function authentication(array $headers): array
    {
        $blob = strtolower((string) ($headers['authentication-results'] ?? ''));
        $spf = $this->matchAuth($blob, 'spf') ?? $this->firstWord(strtolower((string) ($headers['received-spf'] ?? '')));
        $dkim = $this->matchAuth($blob, 'dkim');
        $dmarc = $this->matchAuth($blob, 'dmarc');

        return ['spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc];
    }

    private function matchAuth(string $blob, string $key): ?string
    {
        if ($blob === '' || preg_match('/\b'.preg_quote($key, '/').'\s*=\s*([a-z]+)/', $blob, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function firstWord(string $value): ?string
    {
        if ($value === '' || preg_match('/^([a-z]+)/', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @param list<string> $received
     */
    private function sendingIp(array $received): ?string
    {
        foreach ($received as $line) {
            if (preg_match_all('/\[([0-9a-f:.]+)\]/i', $line, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $ip) {
                if ($this->ssrf->isPublicIp($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * @param array{spf: ?string, dkim: ?string, dmarc: ?string} $auth
     */
    private function aligned(string $fromDomain, string $dkimDomain, array $auth): bool
    {
        if ($fromDomain === '' || $dkimDomain === '') {
            return $auth['dmarc'] === 'pass';
        }

        return $fromDomain === $dkimDomain
            || str_ends_with($fromDomain, '.'.$dkimDomain)
            || str_ends_with($dkimDomain, '.'.$fromDomain)
            || $auth['dmarc'] === 'pass';
    }

    /**
     * @param array<string, string> $headers
     */
    private function headerValue(array $headers, string $name, string $tag): ?string
    {
        $raw = strtolower((string) ($headers[$name] ?? ''));
        if ($raw === '' || preg_match('/(?:^|;)\s*'.preg_quote($tag, '/').'\s*=\s*([^;\s]+)/', $raw, $m) !== 1) {
            return null;
        }

        return trim($m[1], '"');
    }

    private function emailDomain(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m) === 1) {
            $from = $m[1];
        }
        $from = trim($from);
        $at = strrchr($from, '@');

        return $at !== false ? strtolower(trim(substr($at, 1), ' >')) : '';
    }

    private function plainBody(string $raw): string
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $raw, 2);
        $body = $parts[1] ?? '';
        $body = preg_replace('/https?:\/\/\S+/i', ' ', $body) ?? $body;

        return trim(strip_tags(substr($body, 0, 8000)));
    }

    private function hasRecords(mixed $records): bool
    {
        return \is_array($records) && $records !== [];
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= 9.0 => 'excellent',
            $score >= 7.0 => 'good',
            $score >= 5.0 => 'warning',
            default => 'poor',
        };
    }
}
