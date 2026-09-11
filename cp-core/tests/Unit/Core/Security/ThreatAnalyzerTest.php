<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Dto\ThreatResult;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Service\ThreatAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ThreatAnalyzer::class)]
final class ThreatAnalyzerTest extends TestCase
{
    private ThreatAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ThreatAnalyzer();
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $headers
     */
    private function analyze(
        string $path = '/tr',
        array $query = [],
        array $post = [],
        array $headers = [],
        string $method = 'GET',
    ): ThreatResult {
        $request = Request::create($path, $method, $method === 'POST' ? $post : $query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        foreach ($query as $name => $value) {
            $request->query->set($name, $value);
        }

        return $this->analyzer->analyze($request);
    }

    public function testAnOrdinaryRequestIsAPlainPageView(): void
    {
        $result = $this->analyze('/tr/blog/merhaba-dunya', ['page' => '2']);

        self::assertSame(SystemTelemetryLog::EVENT_PAGE_VIEW, $result->eventType);
        self::assertSame(0, $result->threatScore);
        self::assertSame([], $result->details);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqliPayloads(): iterable
    {
        yield 'union select' => ["' UNION SELECT password FROM users --"];
        yield 'or 1=1' => ["admin' OR 1=1"];
        yield 'and 1=1' => ['1 AND 1 = 1'];
        yield 'sleep' => ['1) OR SLEEP(5)'];
        yield 'benchmark' => ['BENCHMARK(1000000,MD5(1))'];
        yield 'information_schema' => ['SELECT * FROM information_schema.tables'];
        yield 'load_file' => ['LOAD_FILE("/etc/shadow")'];
        yield 'into outfile' => ['1 INTO OUTFILE "/var/www/shell.php"'];
        yield 'drop table' => ['x; DROP TABLE users'];
        yield 'versioned comment' => ['/*!50000UNION*/'];
    }

    #[DataProvider('sqliPayloads')]
    public function testSqlInjectionSignaturesScoreHighest(string $payload): void
    {
        $result = $this->analyze('/tr/search', ['q' => $payload]);

        self::assertSame(SystemTelemetryLog::EVENT_SQLI_ATTEMPT, $result->eventType);
        self::assertSame(SystemTelemetryLog::SEVERITY_THREAT, $result->severity);
        self::assertSame(95, $result->threatScore);
        self::assertArrayHasKey('sqli', $result->details);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xssPayloads(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'spaced script tag' => ['< script >alert(1)'];
        yield 'javascript uri' => ['javascript:alert(1)'];
        yield 'onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'onload' => ['<body onload=alert(1)>'];
        yield 'cookie theft' => ['fetch("/x?c="+document.cookie)'];
        yield 'iframe' => ['<iframe src=//evil>'];
        yield 'css expression' => ['width: expression(alert(1))'];
    }

    #[DataProvider('xssPayloads')]
    public function testXssSignaturesAreDetected(string $payload): void
    {
        $result = $this->analyze('/tr/comment', ['body' => $payload]);

        self::assertSame(85, $result->threatScore);
        self::assertSame(SystemTelemetryLog::EVENT_XSS_ATTEMPT, $result->eventType);
        self::assertArrayHasKey('xss', $result->details);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalPayloads(): iterable
    {
        yield 'unix traversal' => ['../../../../etc/passwd'];
        yield 'windows traversal' => ['..\\..\\windows\\win.ini'];
        yield 'dotenv' => ['/.env'];
        yield 'wp-config' => ['/wp-config.php'];
        yield 'proc self environ' => ['/proc/self/environ'];
        yield 'php filter wrapper' => ['php://filter/convert.base64-encode/resource=index'];
    }

    #[DataProvider('traversalPayloads')]
    public function testTraversalSignaturesAreDetected(string $payload): void
    {
        $result = $this->analyze('/tr/download', ['file' => $payload]);

        self::assertSame(80, $result->threatScore);
        self::assertSame(SystemTelemetryLog::EVENT_PATH_TRAVERSAL, $result->eventType);
        self::assertArrayHasKey('path_traversal', $result->details);
    }

    public function testTheHighestScoringCategoryWins(): void
    {
        $result = $this->analyze('/tr/search', ['q' => "<script>1</script>' UNION SELECT 1"]);

        self::assertSame(95, $result->threatScore);
        self::assertSame(SystemTelemetryLog::EVENT_SQLI_ATTEMPT, $result->eventType);
        self::assertArrayHasKey('sqli', $result->details);
        self::assertArrayHasKey('xss', $result->details);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scannerAgents(): iterable
    {
        yield 'sqlmap' => ['sqlmap/1.7.2#stable'];
        yield 'nikto' => ['Mozilla/5.00 (Nikto/2.1.6)'];
        yield 'nuclei' => ['Nuclei - Open-source project'];
        yield 'python-requests' => ['python-requests/2.31.0'];
        yield 'mixed case' => ['SQLMAP/1.0'];
    }

    #[DataProvider('scannerAgents')]
    public function testKnownScannersAreFlagged(string $userAgent): void
    {
        $result = $this->analyze('/tr', headers: ['User-Agent' => $userAgent]);

        self::assertSame(50, $result->threatScore);
        self::assertSame(SystemTelemetryLog::EVENT_SCANNER_DETECTED, $result->eventType);
        self::assertArrayHasKey('scanner', $result->details);
    }

    public function testAnOrdinaryBrowserAgentIsNotAScanner(): void
    {
        $result = $this->analyze('/tr', headers: ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120']);

        self::assertSame(SystemTelemetryLog::EVENT_PAGE_VIEW, $result->eventType);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function loginPaths(): iterable
    {
        yield 'POST /login' => ['/login', 'POST', true];
        yield 'POST the Turkish path' => ['/hesap/giris', 'POST', true];
        yield 'POST with a trailing slash' => ['/login/', 'POST', true];
        yield 'GET the same path' => ['/login', 'GET', false];
        yield 'POST somewhere else' => ['/tr/comment', 'POST', false];
    }

    #[DataProvider('loginPaths')]
    public function testLoginAttemptsAreLabelled(string $path, string $method, bool $isLogin): void
    {
        $result = $this->analyze($path, method: $method);

        if ($isLogin) {
            self::assertSame(SystemTelemetryLog::EVENT_LOGIN_ATTEMPT, $result->eventType);
            self::assertSame(15, $result->threatScore);
        } else {
            self::assertSame(SystemTelemetryLog::EVENT_PAGE_VIEW, $result->eventType);
        }
    }

    public function testAnAttackOnTheLoginPathKeepsTheAttackLabel(): void
    {
        $result = $this->analyze('/login', ['u' => "' OR 1=1"], method: 'POST');

        self::assertSame(SystemTelemetryLog::EVENT_SQLI_ATTEMPT, $result->eventType);
    }

    public function testMatchedRequestsCarryASampleOfTheirParameters(): void
    {
        $result = $this->analyze('/tr/search', ['q' => "' UNION SELECT 1", 'page' => '2']);

        self::assertArrayHasKey('sampled', $result->details);
        self::assertSame(['q' => "' UNION SELECT 1", 'page' => '2'], $result->details['sampled']);
    }

    public function testTheParameterSampleIsBoundedInCountAndLength(): void
    {
        $query = ['q' => "' UNION SELECT 1"];
        for ($i = 0; $i < 30; ++$i) {
            $query['f'.$i] = str_repeat('x', 500);
        }

        $sample = $this->analyze('/tr/search', $query)->details['sampled'];

        self::assertLessThanOrEqual(12, \count($sample));
        foreach ($sample as $value) {
            self::assertLessThanOrEqual(180, mb_strlen((string) $value));
        }
    }

    public function testHeadersAreScannedTooNotJustParameters(): void
    {
        $result = $this->analyze('/tr', headers: ['Referer' => 'https://evil.example/?x=<script>alert(1)</script>']);

        self::assertSame(SystemTelemetryLog::EVENT_XSS_ATTEMPT, $result->eventType);
    }

    public function testCookiesAreScannedToo(): void
    {
        $request = Request::create('/tr');
        $request->cookies->set('pref', "' UNION SELECT 1");

        self::assertSame(SystemTelemetryLog::EVENT_SQLI_ATTEMPT, $this->analyzer->analyze($request)->eventType);
    }

    public function testNestedParameterArraysAreFlattenedAndScanned(): void
    {
        $request = Request::create('/tr', 'POST', ['filter' => ['nested' => ['deep' => "' UNION SELECT 1"]]]);

        self::assertSame(SystemTelemetryLog::EVENT_SQLI_ATTEMPT, $this->analyzer->analyze($request)->eventType);
    }

    /**
     * Twenty-eight regular expressions run over the collected text on every
     * request. Without a cap an attacker buys CPU with bytes: a few megabytes of
     * body turn the WAF into the cheapest denial-of-service vector on the site.
     */
    public function testAHugeRequestDoesNotTurnTheScannerIntoADosVector(): void
    {
        $request = Request::create('/tr', 'POST', [
            'a' => str_repeat('x', 2 * 1024 * 1024),
            'b' => str_repeat('y', 2 * 1024 * 1024),
        ]);

        $started = hrtime(true);
        $result = $this->analyzer->analyze($request);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertSame(SystemTelemetryLog::EVENT_PAGE_VIEW, $result->eventType);
        self::assertLessThan(
            250,
            $elapsedMs,
            'Four megabytes of payload must not cost a quarter of a second of regex time.',
        );
    }

    public function testAPayloadInsideTheCapIsStillCaught(): void
    {
        $request = Request::create('/tr', 'POST', ['q' => str_repeat('x', 1000)."' UNION SELECT 1"]);

        self::assertSame(SystemTelemetryLog::EVENT_SQLI_ATTEMPT, $this->analyzer->analyze($request)->eventType);
    }

    public function testAnalyzingNeverRaisesForAnyMethodOrBody(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
            $result = $this->analyzer->analyze(Request::create('/tr', $method));
            self::assertSame(SystemTelemetryLog::EVENT_PAGE_VIEW, $result->eventType, $method);
        }
    }
}
