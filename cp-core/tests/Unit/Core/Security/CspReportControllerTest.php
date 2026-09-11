<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Controller\CspReportController;
use App\Core\Security\Entity\SystemTelemetryLog;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use App\Tests\Unit\Core\Security\Support\ExplodingCachePool;

#[CoversClass(CspReportController::class)]
final class CspReportControllerTest extends TestCase
{
    private ArraySettings $settings;
    private FloodService $flood;
    private CspReportController $controller;

    /** @var list<array{string, array<string, mixed>}> */
    private array $recorded = [];

    public static function setUpBeforeClass(): void
    {
        SecurityClock::install();
    }

    protected function setUp(): void
    {
        $this->settings = new ArraySettings(['security.flood_enabled' => true]);
        $this->flood = new FloodService(new ArrayAdapter(storeSerialized: false), $this->settings);

        $repository = $this->createMock(TelemetryLogRepository::class);
        $repository->method('insertRow')->willReturnCallback(
            function (string $ip, ?int $userId, string $method, string $uri, string $agent, string $severity, string $eventType, int $score, array $details): void {
                $this->recorded[] = [$eventType, $details];
            },
        );

        $this->controller = new CspReportController(
            new SecurityEventRecorder($repository, new RequestStack(), $this->createMock(Security::class), new NullLogger()),
            $this->flood,
        );
    }

    private function post(string $body, string $ip = '203.0.113.9', ?int $declaredLength = null): Response
    {
        $request = Request::create('/_cp/csp-report', 'POST', [], [], [], [], $body);
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('Content-Type', 'application/csp-report');
        $request->headers->set('Content-Length', (string) ($declaredLength ?? \strlen($body)));

        return ($this->controller)($request);
    }

    private function report(string $blockedUri = 'https://evil.example/x.js'): string
    {
        return (string) json_encode([
            'csp-report' => [
                'violated-directive' => "script-src 'self'",
                'effective-directive' => 'script-src',
                'blocked-uri' => $blockedUri,
                'document-uri' => 'https://example.test/tr',
                'disposition' => 'report',
                'source-file' => 'https://example.test/tr',
                'ignored-field' => 'should not be stored',
            ],
        ]);
    }

    public function testAWellFormedReportIsStoredAsTelemetry(): void
    {
        self::assertSame(Response::HTTP_NO_CONTENT, $this->post($this->report())->getStatusCode());

        self::assertCount(1, $this->recorded);
        self::assertSame(SystemTelemetryLog::EVENT_CSP_VIOLATION, $this->recorded[0][0]);
        self::assertSame([
            'violated_directive' => "script-src 'self'",
            'effective_directive' => 'script-src',
            'blocked_uri' => 'https://evil.example/x.js',
            'document_uri' => 'https://example.test/tr',
            'disposition' => 'report',
            'source_file' => 'https://example.test/tr',
        ], $this->recorded[0][1]);
    }

    public function testOnlyAllowlistedFieldsSurvive(): void
    {
        $this->post((string) json_encode(['csp-report' => [
            'blocked-uri' => 'https://evil.example/x.js',
            'script-sample' => 'alert(document.cookie)',
            'referrer' => 'https://example.test/secret?token=abc',
            'status-code' => 200,
        ]]));

        self::assertSame(['blocked_uri' => 'https://evil.example/x.js'], $this->recorded[0][1]);
    }

    public function testLongFieldsAreTruncated(): void
    {
        $this->post($this->report('https://evil.example/'.str_repeat('a', 1000)));

        self::assertSame(300, mb_strlen($this->recorded[0][1]['blocked_uri']));
    }

    public function testABareReportObjectWithoutTheWrapperIsAccepted(): void
    {
        $this->post((string) json_encode(['blocked-uri' => 'https://evil.example/x.js']));

        self::assertSame(['blocked_uri' => 'https://evil.example/x.js'], $this->recorded[0][1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['<html>not json</html>'];
        yield 'truncated json' => ['{"csp-report": {'];
        yield 'a bare scalar' => ['"just a string"'];
        yield 'json null' => ['null'];
        yield 'an object with no known field' => ['{"csp-report":{"unknown":"x"}}'];
        yield 'an object with non-string values' => ['{"csp-report":{"blocked-uri":{"nested":"object"}}}'];
        yield 'empty strings' => ['{"csp-report":{"blocked-uri":""}}'];
    }

    /**
     * Nothing a browser (or a prankster) can post may produce anything other than
     * a silent 204 — the endpoint is unauthenticated by necessity.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableBodies')]
    public function testAnUnusableBodyIsAcceptedAndStoresNothing(string $body): void
    {
        self::assertSame(Response::HTTP_NO_CONTENT, $this->post($body)->getStatusCode());
        self::assertSame([], $this->recorded);
    }

    public function testDeeplyNestedJsonIsRejectedRatherThanWalked(): void
    {
        $payload = '{"csp-report":'.str_repeat('{"a":', 30).'1'.str_repeat('}', 30).'}';

        self::assertSame(Response::HTTP_NO_CONTENT, $this->post($payload)->getStatusCode());
        self::assertSame([], $this->recorded);
    }

    public function testABodyOverTheCapIsIgnored(): void
    {
        $oversized = (string) json_encode(['csp-report' => ['blocked-uri' => str_repeat('a', 9000)]]);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->post($oversized)->getStatusCode());
        self::assertSame([], $this->recorded);
    }

    /**
     * Regression: the 8 KB cap was applied after getContent() had already buffered
     * the whole body, so an unauthenticated caller could still make us hold an
     * arbitrarily large POST in memory before being told it was too big.
     */
    public function testAnOversizedDeclaredLengthIsRefusedBeforeTheBodyIsRead(): void
    {
        $request = Request::create('/_cp/csp-report', 'POST', [], [], [], [], $this->report());
        $request->server->set('REMOTE_ADDR', '203.0.113.9');
        $request->headers->set('Content-Length', (string) (50 * 1024 * 1024));

        self::assertSame(Response::HTTP_NO_CONTENT, ($this->controller)($request)->getStatusCode());
        self::assertSame([], $this->recorded, 'The body was never parsed.');
    }

    // --- flood limiting -----------------------------------------------------

    public function testTheEndpointIsFloodLimitedPerHost(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(Response::HTTP_NO_CONTENT, $this->post($this->report())->getStatusCode(), 'report '.$i);
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->post($this->report())->getStatusCode());
        self::assertCount(30, $this->recorded);
    }

    public function testTheLimitIsPerHost(): void
    {
        for ($i = 0; $i < 31; ++$i) {
            $this->post($this->report(), '203.0.113.9');
        }

        self::assertSame(Response::HTTP_NO_CONTENT, $this->post($this->report(), '198.51.100.4')->getStatusCode());
    }

    /**
     * Anonymous abuse endpoints fail closed: if the limiter cannot answer, the
     * report is refused rather than written.
     */
    public function testTheLimiterFailsClosedWhenTheCacheIsUnreachable(): void
    {
        $controller = new CspReportController(
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
            new FloodService(new ExplodingCachePool(), $this->settings),
        );

        $request = Request::create('/_cp/csp-report', 'POST', [], [], [], [], $this->report());
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $controller($request)->getStatusCode());
    }

    public function testAMissingClientIpStillGetsACounter(): void
    {
        $request = Request::create('/_cp/csp-report', 'POST', [], [], [], [], $this->report());
        $request->server->remove('REMOTE_ADDR');

        self::assertSame(Response::HTTP_NO_CONTENT, ($this->controller)($request)->getStatusCode());
        self::assertSame(1, $this->flood->count(FloodService::EVENT_CSP_REPORT, '0.0.0.0', 300));
    }
}
