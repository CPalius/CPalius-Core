<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Dto\ThreatResult;
use App\Core\Security\EventListener\RequestGuardSubscriber;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Service\IpMatcher;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Security\Service\ThreatAnalyzer;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(RequestGuardSubscriber::class)]
final class RequestGuardSubscriberTest extends TestCase
{
    private const BANNED_IP = '203.0.113.9';

    private Connection $connection;
    private ArraySettings $settings;
    private IpBanService $bans;
    private RequestGuardSubscriber $guard;

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();
        $this->settings = new ArraySettings(['security.waf_mode' => RequestGuardSubscriber::MODE_BLOCK]);
        $this->bans = new IpBanService($this->connection, new IpMatcher(), $this->settings);

        $this->guard = new RequestGuardSubscriber(
            $this->bans,
            new ThreatAnalyzer(),
            $this->settings,
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * @param array<string, string> $query
     */
    private function handle(string $path, string $ip = self::BANNED_IP, array $query = [], string $host = 'example.test'): RequestEvent
    {
        $request = Request::create('http://'.$host.$path, 'GET', $query);
        $request->server->set('REMOTE_ADDR', $ip);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->guard->onKernelRequest($event);

        return $event;
    }

    // --- ban enforcement ---------------------------------------------------

    public function testABannedHostIsRefusedOnAnOrdinaryPage(): void
    {
        $this->bans->ban(self::BANNED_IP);

        $event = $this->handle('/tr/blog');

        self::assertInstanceOf(Response::class, $event->getResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()->getStatusCode());
        self::assertSame('ip_ban', $event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_BLOCKED));
    }

    public function testABannedHostIsAlsoRefusedOnTheAdminSurface(): void
    {
        // Leaving /aacp reachable meant a banned host could keep hammering the
        // admin login form.
        $this->bans->ban(self::BANNED_IP);

        self::assertNotNull($this->handle('/aacp/dashboard')->getResponse());
        self::assertNotNull($this->handle('/login')->getResponse());
    }

    public function testAnUnbannedHostPassesThrough(): void
    {
        $this->bans->ban('198.51.100.4');

        self::assertNull($this->handle('/tr/blog')->getResponse());
    }

    public function testAnAllowlistedHostIsNeverRefused(): void
    {
        $this->bans->ban('203.0.113.0/24');
        $this->settings->put('security.ip_allowlist', self::BANNED_IP);

        self::assertNull($this->handle('/tr/blog')->getResponse());
    }

    // --- the single exemption ----------------------------------------------

    public function testTheRecoveryConsoleStaysReachableForABannedHost(): void
    {
        $this->bans->ban(self::BANNED_IP);

        self::assertNull($this->handle('/aacp/recovery')->getResponse());
        self::assertNull($this->handle('/aacp/recovery/settings')->getResponse());
    }

    /**
     * Regression: the exemption was a bare prefix test, so any route whose path
     * merely started with the same letters inherited it.
     */
    public function testTheExemptionStopsAtAPathBoundary(): void
    {
        $this->bans->ban(self::BANNED_IP);

        self::assertNotNull($this->handle('/aacp/recovery-console')->getResponse());
        self::assertNotNull($this->handle('/aacp/recoveryx')->getResponse());
    }

    /**
     * Regression: the profiler and the toolbar shared the ban exemption. They are
     * dev-only routes, but a route that skips the perimeter is still a route that
     * skips the perimeter if the bundle is ever enabled in production.
     */
    public function testTheProfilerIsNotExemptFromTheBanList(): void
    {
        $this->bans->ban(self::BANNED_IP);

        self::assertNotNull($this->handle('/_profiler/abc')->getResponse());
        self::assertNotNull($this->handle('/_wdt/abc')->getResponse());
    }

    public function testTheProfilerIsStillSkippedBySignatureScanning(): void
    {
        // Profiler URLs carry serialised request data that trips the traversal and
        // XSS signatures; scanning them is pure false positives.
        $event = $this->handle('/_profiler/abc', '198.51.100.4', ['panel' => '../../etc/passwd<script>']);

        self::assertNull($event->getResponse());
        self::assertNull($event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_THREAT));
    }

    // --- host allowlist -----------------------------------------------------

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function hostPatterns(): iterable
    {
        yield 'empty list allows everything' => ['', 'anything.test', true];
        yield 'whitespace-only list allows everything' => ["  \n ", 'anything.test', true];
        yield 'exact match' => ['example.test', 'example.test', true];
        yield 'exact mismatch' => ['example.test', 'evil.test', false];
        yield 'case is ignored' => ['Example.Test', 'EXAMPLE.test', true];
        yield 'multiple entries' => ["a.test\nb.test, c.test", 'b.test', true];
        yield 'wildcard matches a subdomain' => ['*.example.test', 'www.example.test', true];
        yield 'wildcard matches the apex' => ['*.example.test', 'example.test', true];
        yield 'wildcard matches a deep subdomain' => ['*.example.test', 'a.b.example.test', true];
        yield 'wildcard does not match a lookalike suffix' => ['*.example.test', 'evilexample.test', false];
        yield 'wildcard does not match another domain' => ['*.example.test', 'example.evil', false];
    }

    #[DataProvider('hostPatterns')]
    public function testHostAllowlist(string $patterns, string $host, bool $allowed): void
    {
        $this->settings->put('security.trusted_hosts', $patterns);

        $event = $this->handle('/tr', '198.51.100.4', host: $host);

        if ($allowed) {
            self::assertNull($event->getResponse());
        } else {
            self::assertNotNull($event->getResponse());
            self::assertSame('host', $event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_BLOCKED));
        }
    }

    public function testTheRecoveryConsoleIsAlsoExemptFromTheHostAllowlist(): void
    {
        // A wrong host list is the classic self-inflicted lockout, and recovery is
        // the documented way back.
        $this->settings->put('security.trusted_hosts', 'example.test');

        self::assertNull($this->handle('/aacp/recovery', '198.51.100.4', host: 'wrong.test')->getResponse());
    }

    // --- WAF ----------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function attackPayloads(): iterable
    {
        yield 'union select' => ['1 UNION SELECT password FROM users'];
        yield 'information_schema' => ['1 AND information_schema.tables'];
        yield 'sleep' => ['1; SLEEP(10)'];
    }

    #[DataProvider('attackPayloads')]
    public function testBlockModeRefusesAMatchedSignature(string $payload): void
    {
        $event = $this->handle('/tr/search', '198.51.100.4', ['q' => $payload]);

        self::assertNotNull($event->getResponse());
        self::assertSame('waf', $event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_BLOCKED));
    }

    public function testDetectModeRecordsTheVerdictWithoutRefusing(): void
    {
        $this->settings->put('security.waf_mode', RequestGuardSubscriber::MODE_DETECT);

        $event = $this->handle('/tr/search', '198.51.100.4', ['q' => '1 UNION SELECT 1']);

        self::assertNull($event->getResponse());
        self::assertInstanceOf(ThreatResult::class, $event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_THREAT));
    }

    public function testOffModeScansNothingAtAll(): void
    {
        $this->settings->put('security.waf_mode', RequestGuardSubscriber::MODE_OFF);

        $event = $this->handle('/tr/search', '198.51.100.4', ['q' => '1 UNION SELECT 1']);

        self::assertNull($event->getResponse());
        self::assertNull($event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_THREAT));
    }

    public function testAnUnknownWafModeBehavesAsDetect(): void
    {
        $this->settings->put('security.waf_mode', 'blockk');

        $event = $this->handle('/tr/search', '198.51.100.4', ['q' => '1 UNION SELECT 1']);

        self::assertNull($event->getResponse(), 'A typo must not silently enable blocking...');
        self::assertNotNull(
            $event->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_THREAT),
            '...nor silently disable detection.',
        );
    }

    public function testTheVerdictIsStashedSoTelemetryDoesNotRescanTheRequest(): void
    {
        $this->settings->put('security.waf_mode', RequestGuardSubscriber::MODE_DETECT);

        $result = $this->handle('/tr/search', '198.51.100.4', ['q' => '1 UNION SELECT 1'])
            ->getRequest()->attributes->get(RequestGuardSubscriber::ATTR_THREAT);

        self::assertInstanceOf(ThreatResult::class, $result);
        self::assertSame(95, $result->threatScore);
        self::assertArrayHasKey('sqli', $result->details);
    }

    public function testAHighScoringPayloadAlsoBansTheSource(): void
    {
        $this->handle('/tr/search', '198.51.100.4', ['q' => '1 UNION SELECT 1']);

        self::assertTrue($this->bans->isBanned('198.51.100.4'));

        $row = $this->connection->fetchAssociative('SELECT source, reason FROM cp_banned_ips');
        self::assertIsArray($row);
        self::assertSame(IpBanService::SOURCE_AUTO, $row['source']);
        self::assertStringStartsWith('waf:', (string) $row['reason']);
    }

    public function testAutoBanningCanBeTurnedOffWithoutTurningOffBlocking(): void
    {
        $this->settings->put('security.waf_autoban_score', 0);

        $event = $this->handle('/tr/search', '198.51.100.4', ['q' => '1 UNION SELECT 1']);

        self::assertNotNull($event->getResponse(), 'Still blocked...');
        self::assertFalse($this->bans->isBanned('198.51.100.4'), '...but not banned.');
    }

    public function testAScoreBelowTheBlockThresholdIsNotRefused(): void
    {
        // A scanner user-agent scores 50; the default block threshold is 80.
        $request = Request::create('http://example.test/tr');
        $request->server->set('REMOTE_ADDR', '198.51.100.4');
        $request->headers->set('User-Agent', 'sqlmap/1.7');

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
        $this->guard->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    // --- failure behaviour ---------------------------------------------------

    public function testTheDenialIsIdenticalForEveryCauseSoAProbeLearnsNothing(): void
    {
        $this->bans->ban(self::BANNED_IP);
        $this->settings->put('security.trusted_hosts', 'example.test');

        $banned = $this->handle('/tr')->getResponse();
        $wrongHost = $this->handle('/tr', '198.51.100.4', host: 'evil.test')->getResponse();
        $waf = $this->handle('/tr/search', '198.51.100.5', ['q' => '1 UNION SELECT 1'])->getResponse();

        self::assertNotNull($banned);
        self::assertNotNull($wrongHost);
        self::assertNotNull($waf);

        self::assertSame($banned->getContent(), $wrongHost->getContent());
        self::assertSame($banned->getContent(), $waf->getContent());
        self::assertSame($banned->getStatusCode(), $wrongHost->getStatusCode());
        self::assertSame('nosniff', $banned->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $banned->headers->get('Cache-Control'));
    }

    /**
     * The perimeter fails open on its own errors: a hardening layer that takes the
     * site down is a bigger outage than the attack it was watching for.
     */
    public function testABrokenGuardLetsTheRequestThroughInsteadOfRaising(): void
    {
        $this->connection->executeStatement('DROP TABLE cp_banned_ips');

        $event = $this->handle('/tr');

        self::assertNull($event->getResponse());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $this->bans->ban(self::BANNED_IP);

        $request = Request::create('http://example.test/tr');
        $request->server->set('REMOTE_ADDR', self::BANNED_IP);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );
        $this->guard->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testItRunsEarlyEnoughToPrecedeTheFirewall(): void
    {
        $events = RequestGuardSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 512], $events['kernel.request']);
    }
}
