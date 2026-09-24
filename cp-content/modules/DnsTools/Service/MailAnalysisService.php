<?php

declare(strict_types=1);

namespace Modules\DnsTools\Service;

final class MailAnalysisService
{
    public function __construct(
        private readonly DnsRecordService $dns,
        private readonly BlacklistService $blacklist,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function headers(string $raw): array
    {
        $raw = substr($raw, 0, 50000);
        $lines = preg_split('/\R/', $raw) ?: [];
        $headers = [];
        $received = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }
            if ($current !== null && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                if ($current === 'received') {
                    $last = array_key_last($received);
                    if ($last !== null) {
                        $received[$last] .= ' '.trim($line);
                    }
                } else {
                    $headers[$current] .= ' '.trim($line);
                }
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $value = trim($value);
            if ($current === 'received') {
                $received[] = $value;
                continue;
            }
            $headers[$current] = $value;
        }
        $headers['received'] = implode("\n", $received);

        return [
            'from' => $this->decodeHeader($headers['from'] ?? null),
            'to' => $this->decodeHeader($headers['to'] ?? null),
            'subject' => $this->decodeHeader($headers['subject'] ?? null),
            'date' => $headers['date'] ?? null,
            'message_id' => $headers['message-id'] ?? null,
            'spf' => $headers['received-spf'] ?? ($headers['authentication-results'] ?? null),
            'dkim' => $headers['dkim-signature'] ?? null,
            'received' => $received,
            'headers' => $headers,
        ];
    }

    private function decodeHeader(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return \is_string($value) ? $value : null;
        }
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (!\is_string($decoded) || $decoded === '') {
            return $value;
        }
        $clean = preg_replace('/\s+/u', ' ', $decoded);

        return trim(\is_string($clean) ? $clean : $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    public function emailSecurity(string $email): array
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '') {
            throw new \InvalidArgumentException('dnstools.error.invalid_email');
        }

        $mx = $this->dns->records($domain, DNS_MX);
        $spf = $this->dns->mailPolicy($domain, 'spf');
        $dmarc = $this->dns->mailPolicy($domain, 'dmarc');
        $dkim = $this->dns->mailPolicy($domain, 'dkim');
        $blacklist = [];
        $mxHost = rtrim((string) ($mx[0]['target'] ?? ''), '.');
        if ($mxHost !== '') {
            $ips = array_column($this->dns->records($mxHost, DNS_A), 'ip');
            if (isset($ips[0])) {
                $blacklist = $this->blacklist->check($ips[0]);
            }
        }

        return [
            'email' => $email,
            'domain' => $domain,
            'mx' => $mx,
            'spf' => $spf,
            'dmarc' => $dmarc,
            'dkim' => $dkim,
            'blacklist' => $blacklist,
        ];
    }

    /**
     * Domain-side deliverability without receiving a message.
     *
     * @return array<string, mixed>
     */
    public function deliverability(string $email): array
    {
        $base = $this->emailSecurity($email);
        $domain = (string) $base['domain'];
        $score = 10.0;
        $checks = [];

        $mx = \is_array($base['mx'] ?? null) ? $base['mx'] : [];
        if ($mx === []) {
            $score -= 3.0;
            $checks[] = ['name' => 'mx', 'result' => 'missing', 'points' => -3.0];
        } else {
            $checks[] = ['name' => 'mx', 'result' => 'present', 'points' => 0.0];
        }

        $spfRecords = \is_array($base['spf']['records'] ?? null) ? $base['spf']['records'] : [];
        if ($spfRecords === []) {
            $score -= 2.0;
            $checks[] = ['name' => 'spf', 'result' => 'missing', 'points' => -2.0];
        } else {
            $checks[] = ['name' => 'spf', 'result' => 'present', 'points' => 0.0];
        }

        $dmarcRecords = \is_array($base['dmarc']['records'] ?? null) ? $base['dmarc']['records'] : [];
        if ($dmarcRecords === []) {
            $score -= 2.0;
            $checks[] = ['name' => 'dmarc', 'result' => 'missing', 'points' => -2.0];
        } else {
            $checks[] = ['name' => 'dmarc', 'result' => 'present', 'points' => 0.0];
        }

        $dkimFound = false;
        foreach (['default', 'google', 'selector1', 's1', 'k1', 'mail'] as $selector) {
            $probe = $this->dns->mailPolicy($domain, 'dkim', $selector);
            if ((\is_array($probe['records'] ?? null) ? $probe['records'] : []) !== []) {
                $dkimFound = true;
                $base['dkim'] = $probe;
                break;
            }
        }
        if (!$dkimFound) {
            $score -= 1.5;
            $checks[] = ['name' => 'dkim', 'result' => 'missing', 'points' => -1.5];
        } else {
            $checks[] = ['name' => 'dkim', 'result' => 'present', 'points' => 0.0];
        }

        $listed = (int) ($base['blacklist']['listed_count'] ?? 0);
        if ($listed > 0) {
            $score -= 2.0;
            $checks[] = ['name' => 'blacklist', 'result' => 'blacklisted', 'points' => -2.0];
        }

        $mxHost = rtrim((string) ($mx[0]['target'] ?? ''), '.');
        $ptr = null;
        $forward = false;
        if ($mxHost !== '') {
            $ips = array_column($this->dns->records($mxHost, DNS_A), 'ip');
            $ip = $ips[0] ?? null;
            if (\is_string($ip) && $ip !== '') {
                $rev = $this->dns->reverseIp($ip);
                $ptr = $rev['ptr'] ?? null;
                $forward = ($rev['forward_confirmed'] ?? false) === true;
                if ($ptr === null || $ptr === '') {
                    $score -= 1.0;
                    $checks[] = ['name' => 'ptr', 'result' => 'missing', 'points' => -1.0];
                } elseif (!$forward) {
                    $score -= 0.5;
                    $checks[] = ['name' => 'ptr', 'result' => 'warning', 'points' => -0.5];
                }
            }
        }

        $score = max(0.0, min(10.0, round($score, 1)));

        return $base + [
            'score' => $score,
            'grade' => match (true) {
                $score >= 9.0 => 'excellent',
                $score >= 7.0 => 'good',
                $score >= 5.0 => 'warning',
                default => 'poor',
            },
            'ptr' => $ptr,
            'forward_confirmed' => $forward,
            'checks' => $checks,
            'note' => 'dns_only',
        ];
    }
}
