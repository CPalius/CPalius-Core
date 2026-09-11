<?php

declare(strict_types=1);

namespace App\Core\Security\Service;

use App\Core\Security\Dto\ThreatResult;
use App\Core\Security\Entity\SystemTelemetryLog;
use Symfony\Component\HttpFoundation\Request;

/**
 * Signature scanner for SQLi, XSS, LFI/path traversal and known attack tooling.
 */
final class ThreatAnalyzer
{
    private const LOGIN_PATHS = [
        '/login',
        '/hesap/giris',
        '/account/login',
        '/admin/login',
    ];

    /** @var list<array{pattern: string, label: string}> */
    private const SQLI_SIGNATURES = [
        ['pattern' => '/union\s+select/i', 'label' => 'UNION SELECT'],
        ['pattern' => '/or\s+1\s*=\s*1/i', 'label' => 'OR 1=1'],
        ['pattern' => '/and\s+1\s*=\s*1/i', 'label' => 'AND 1=1'],
        ['pattern' => '/sleep\s*\(/i', 'label' => 'SLEEP()'],
        ['pattern' => '/benchmark\s*\(/i', 'label' => 'BENCHMARK()'],
        ['pattern' => '/information_schema/i', 'label' => 'information_schema'],
        ['pattern' => '/load_file\s*\(/i', 'label' => 'LOAD_FILE()'],
        ['pattern' => '/into\s+(out|dump)file/i', 'label' => 'INTO OUTFILE'],
        ['pattern' => '/;\s*drop\s+table/i', 'label' => 'DROP TABLE'],
        ['pattern' => '/\'\s*or\s+\'/i', 'label' => "quote-OR"],
        ['pattern' => '/--\s*$/m', 'label' => 'SQL comment --'],
        ['pattern' => '/\/\*!\d+/', 'label' => 'MySQL versioned comment'],
    ];

    /** @var list<array{pattern: string, label: string}> */
    private const XSS_SIGNATURES = [
        ['pattern' => '/<\s*script\b/i', 'label' => '<script>'],
        ['pattern' => '/javascript\s*:/i', 'label' => 'javascript:'],
        ['pattern' => '/onerror\s*=/i', 'label' => 'onerror='],
        ['pattern' => '/onload\s*=/i', 'label' => 'onload='],
        ['pattern' => '/document\.cookie/i', 'label' => 'document.cookie'],
        ['pattern' => '/<\s*iframe\b/i', 'label' => '<iframe>'],
        ['pattern' => '/<\s*svg\b[^>]*onload/i', 'label' => 'svg onload'],
        ['pattern' => '/expression\s*\(/i', 'label' => 'CSS expression()'],
    ];

    /** @var list<array{pattern: string, label: string}> */
    private const TRAVERSAL_SIGNATURES = [
        ['pattern' => '/\.\.\//', 'label' => '../'],
        ['pattern' => '/\.\.\\\\/', 'label' => '..\\'],
        ['pattern' => '/\/etc\/passwd/i', 'label' => '/etc/passwd'],
        ['pattern' => '/win\.ini/i', 'label' => 'win.ini'],
        ['pattern' => '/\.env(\b|$)/i', 'label' => '.env'],
        ['pattern' => '/wp-config\.php/i', 'label' => 'wp-config.php'],
        ['pattern' => '/proc\/self\/environ/i', 'label' => 'proc/self/environ'],
        ['pattern' => '/php:\/\/(filter|input)/i', 'label' => 'php:// wrapper'],
    ];

    /** @var list<string> */
    private const SCANNER_AGENTS = [
        'sqlmap',
        'nikto',
        'acunetix',
        'nmap',
        'python-requests',
        'gobuster',
        'w3af',
        'masscan',
        'dirbuster',
        'wfuzz',
        'nuclei',
        'zgrab',
        'httpx',
    ];

    /**
     * Ceiling on the text handed to the signature engine.
     *
     * Twenty-eight regular expressions run over this blob on every single request.
     * Without a cap, a request body of a few megabytes turns the WAF into the
     * cheapest denial-of-service vector on the site — the attacker sends bytes, we
     * spend CPU. Real attack payloads are tiny; anything past the cap is noise.
     */
    private const MAX_HAYSTACK = 65536;

    /** Per-chunk cap, so one enormous field cannot consume the whole budget. */
    private const MAX_CHUNK = 16384;

    public function analyze(Request $request): ThreatResult
    {
        $haystack = $this->collectHaystack($request);
        $matches = [];
        $score = 0;
        $severity = SystemTelemetryLog::SEVERITY_INFO;
        $eventType = SystemTelemetryLog::EVENT_PAGE_VIEW;

        $sqli = $this->matchSignatures($haystack, self::SQLI_SIGNATURES);
        if ($sqli !== []) {
            $matches['sqli'] = $sqli;
            $score = max($score, 95);
            $severity = SystemTelemetryLog::SEVERITY_THREAT;
            $eventType = SystemTelemetryLog::EVENT_SQLI_ATTEMPT;
        }

        $xss = $this->matchSignatures($haystack, self::XSS_SIGNATURES);
        if ($xss !== []) {
            $matches['xss'] = $xss;
            $score = max($score, 85);
            if ($score < 95) {
                $severity = SystemTelemetryLog::SEVERITY_CRITICAL;
                $eventType = SystemTelemetryLog::EVENT_XSS_ATTEMPT;
            }
        }

        $lfi = $this->matchSignatures($haystack, self::TRAVERSAL_SIGNATURES);
        if ($lfi !== []) {
            $matches['path_traversal'] = $lfi;
            $score = max($score, 80);
            if ($eventType === SystemTelemetryLog::EVENT_PAGE_VIEW) {
                $severity = SystemTelemetryLog::SEVERITY_CRITICAL;
                $eventType = SystemTelemetryLog::EVENT_PATH_TRAVERSAL;
            }
        }

        $scanner = $this->matchScanner($request->headers->get('User-Agent', ''));
        if ($scanner !== null) {
            $matches['scanner'] = $scanner;
            $score = max($score, 50);
            if ($eventType === SystemTelemetryLog::EVENT_PAGE_VIEW) {
                $severity = SystemTelemetryLog::SEVERITY_WARNING;
                $eventType = SystemTelemetryLog::EVENT_SCANNER_DETECTED;
            }
        }

        if ($this->isLoginAttempt($request) && $eventType === SystemTelemetryLog::EVENT_PAGE_VIEW) {
            $matches['login'] = $request->getPathInfo();
            $score = max($score, 15);
            $severity = SystemTelemetryLog::SEVERITY_WARNING;
            $eventType = SystemTelemetryLog::EVENT_LOGIN_ATTEMPT;
        }

        if ($matches === []) {
            return ThreatResult::pageView();
        }

        $matches['sampled'] = $this->sampleParameters($request);

        return new ThreatResult($severity, $eventType, $score, $matches);
    }

    /**
     * @return list<string>
     */
    private function collectHaystack(Request $request): array
    {
        $chunks = [
            $request->getRequestUri(),
            $request->headers->get('User-Agent', ''),
            $request->headers->get('Referer', ''),
            $request->headers->get('Cookie', ''),
        ];

        foreach ([$request->query->all(), $request->request->all(), $request->cookies->all()] as $bag) {
            $chunks[] = $this->flatten($bag);
        }

        return array_map(
            static fn (string $chunk): string => substr($chunk, 0, self::MAX_CHUNK),
            $chunks,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function flatten(array $data): string
    {
        $parts = [];
        array_walk_recursive($data, static function (mixed $value) use (&$parts): void {
            if (\is_scalar($value)) {
                $parts[] = (string) $value;
            }
        });

        return implode("\n", $parts);
    }

    /**
     * @param list<string> $haystack
     * @param list<array{pattern: string, label: string}> $signatures
     *
     * @return list<string>
     */
    private function matchSignatures(array $haystack, array $signatures): array
    {
        $blob = substr(implode("\n", $haystack), 0, self::MAX_HAYSTACK);
        $hit = [];

        foreach ($signatures as $signature) {
            if (preg_match($signature['pattern'], $blob) === 1) {
                $hit[] = $signature['label'];
            }
        }

        return $hit;
    }

    private function matchScanner(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);
        foreach (self::SCANNER_AGENTS as $needle) {
            if (str_contains($ua, $needle)) {
                return $needle;
            }
        }

        return null;
    }

    private function isLoginAttempt(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        $path = rtrim($request->getPathInfo(), '/') ?: '/';
        foreach (self::LOGIN_PATHS as $loginPath) {
            if (strcasecmp($path, $loginPath) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function sampleParameters(Request $request): array
    {
        $sample = [];
        foreach (array_merge($request->query->all(), $request->request->all()) as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\is_scalar($value)) {
                $sample[$key] = mb_substr((string) $value, 0, 180);
            }
            if (\count($sample) >= 12) {
                break;
            }
        }

        return $sample;
    }
}
