<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

use App\Core\Security\Entity\SystemTelemetryLog;
use Doctrine\DBAL\Connection;

/**
 * Turns the CSP violation reports the browsers sent into "what would you allow, and where".
 *
 * Reports are untrusted input (anyone can POST one), so the advice is deliberately conservative: only https
 * origins are ever suggested as an allowlist entry, "inline" and "eval" are never suggested (the fix is the
 * template, not a weaker policy), and every row carries how many distinct pages reported it so a single noisy
 * report does not look like a pattern.
 */
final class CspViolationAdvisor
{
    /** effective directive => the security.* setting that widens it */
    private const SETTING_BY_DIRECTIVE = [
        'script-src' => 'security.csp_script_src',
        'script-src-elem' => 'security.csp_script_src',
        'style-src' => 'security.csp_style_src',
        'style-src-elem' => 'security.csp_style_src',
        'img-src' => 'security.csp_img_src',
        'connect-src' => 'security.csp_connect_src',
        'frame-src' => 'security.csp_frame_src',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{directive: string, blocked: string, reports: int, pages: int, advice: string, setting: ?string, value: ?string}>
     */
    public function advise(int $days = 7): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT details FROM cp_system_telemetry_logs WHERE event_type = :type AND created_at >= :since ORDER BY id DESC LIMIT 5000',
            ['type' => SystemTelemetryLog::EVENT_CSP_VIOLATION, 'since' => (new \DateTimeImmutable('-'.max(1, $days).' days'))->format('Y-m-d H:i:s')],
        );

        $groups = [];
        foreach ($rows as $row) {
            $d = json_decode((string) $row['details'], true);
            if (!\is_array($d)) {
                continue;
            }
            $directive = strtolower(strtok((string) ($d['effective_directive'] ?? $d['violated_directive'] ?? ''), ' ') ?: '');
            if ($directive === '') {
                continue;
            }
            $blocked = self::classify((string) ($d['blocked_uri'] ?? ''));
            $key = $directive.'|'.$blocked;
            $groups[$key]['directive'] = $directive;
            $groups[$key]['blocked'] = $blocked;
            $groups[$key]['reports'] = ($groups[$key]['reports'] ?? 0) + 1;
            $groups[$key]['pages'][(string) preg_replace('/[?#].*$/', '', (string) ($d['document_uri'] ?? ''))] = true;
        }

        $out = [];
        foreach ($groups as $g) {
            $out[] = $this->describe($g['directive'], $g['blocked'], $g['reports'], \count($g['pages']));
        }
        usort($out, static fn (array $a, array $b): int => $b['reports'] <=> $a['reports']);

        return $out;
    }

    /** "https://cdn.example.com/a/b.js?x" becomes "https://cdn.example.com"; keywords stay keywords. */
    public static function classify(string $blocked): string
    {
        $blocked = trim($blocked);
        if ($blocked === '') {
            return 'inline';
        }
        if (\in_array($blocked, ['inline', 'eval', 'data', 'blob', 'self'], true)) {
            return $blocked;
        }
        if (preg_match('~^(https?|wss?)://([a-z0-9.\-]+)(:\d+)?~i', $blocked, $m) === 1) {
            return strtolower($m[1].'://'.$m[2].($m[3] ?? ''));
        }

        return preg_match('~^([a-z][a-z0-9+.\-]*):~i', $blocked, $m) === 1 ? strtolower($m[1]) : 'other';
    }

    /** @return array{directive: string, blocked: string, reports: int, pages: int, advice: string, setting: ?string, value: ?string} */
    private function describe(string $directive, string $blocked, int $reports, int $pages): array
    {
        $setting = self::SETTING_BY_DIRECTIVE[$directive] ?? null;
        $row = ['directive' => $directive, 'blocked' => $blocked, 'reports' => $reports, 'pages' => $pages, 'setting' => null, 'value' => null];

        if ($blocked === 'inline' || $blocked === 'eval') {
            $what = $blocked === 'eval' ? 'eval()' : 'script/style';

            return ['advice' => 'Do not allow. Give the inline '.$what.' a nonce or move it to a file; unsafe-'.$blocked.' would switch the protection off.'] + $row;
        }
        if ($blocked === 'data' || $blocked === 'blob') {
            return ['advice' => 'Usually a data: or blob: image or font. Allow only for img-src if the site needs it; never for script-src.'] + $row;
        }
        if ($setting !== null && str_starts_with($blocked, 'https://')) {
            return ['setting' => $setting, 'value' => $blocked, 'advice' => 'If this is a service you use on purpose, add the origin to '.$setting.'. If you do not recognise it, it is probably injected content or a browser extension: leave it blocked.'] + $row;
        }

        return ['advice' => 'Plain http, an unknown scheme or a directive with no setting: leave it blocked.'] + $row;
    }
}
