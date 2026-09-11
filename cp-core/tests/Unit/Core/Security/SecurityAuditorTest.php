<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Audit\SecurityAuditor;
use App\Core\Security\Audit\SecurityFinding;
use App\Core\Security\CapabilityRegistry;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Http\SecurityHeaderPolicy;
use App\Core\Security\Password\BreachChecker;
use App\Core\Security\Password\PasswordHistory;
use App\Core\Security\Password\PasswordPolicy;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\RoleConfigManager;
use App\Core\Security\SecretBox;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Service\IpMatcher;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Core\Security\TwoFactor\TotpGenerator;
use App\Core\Security\TwoFactor\TwoFactorService;
use App\Repository\UserRepository;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityClock;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Translation\IdentityTranslator;

#[CoversClass(SecurityAuditor::class)]
#[CoversClass(SecurityFinding::class)]
final class SecurityAuditorTest extends TestCase
{
    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        SecurityClock::install();
    }

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function auditor(
        array $settings = [],
        ?RequestStack $stack = null,
        bool $debug = false,
        string $environment = 'prod',
    ): SecurityAuditor {
        $registry = new ArraySettings($settings);
        $cache = new ArrayAdapter();

        $twoFactor = new TwoFactorService(
            new TotpGenerator(),
            new SecretBox('kernel-secret'),
            $registry,
            new RoleConfigManager(new CapabilityRegistry(), sys_get_temp_dir().'/cp-no-roles', $cache),
            $this->createMock(EntityManagerInterface::class),
            new FloodService($cache, $registry),
            new SecurityEventRecorder(
                $this->createMock(TelemetryLogRepository::class),
                new RequestStack(),
                $this->createMock(Security::class),
                new NullLogger(),
            ),
            'kernel-secret',
        );

        return new SecurityAuditor(
            $registry,
            new SecurityHeaderPolicy($registry),
            new PasswordPolicy(
                $registry,
                new BreachChecker(
                    new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503])),
                    $cache,
                ),
                new PasswordHistory($this->connection, new PasswordHasherFactory([
                    PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4],
                ])),
                new IdentityTranslator(),
            ),
            $twoFactor,
            new IpBanService($this->connection, new IpMatcher(), $registry),
            $stack ?? new RequestStack(),
            $this->createMock(UserRepository::class),
            $this->connection,
            $debug,
            $environment,
        );
    }

    /**
     * @param list<SecurityFinding> $findings
     *
     * @return array<string, string>
     */
    private function bySeverity(array $findings): array
    {
        $map = [];
        foreach ($findings as $finding) {
            $map[$finding->id] = $finding->severity;
        }

        return $map;
    }

    /**
     * The out-of-the-box posture, audited from the console (no request). Pinning
     * the whole result means any future change to a default has to be an explicit
     * decision rather than a silent drift.
     */
    public function testTheDefaultInstallProducesTheExpectedFindingSet(): void
    {
        $auditor = $this->auditor();
        $findings = $auditor->run();

        self::assertSame([
            'env.debug' => SecurityFinding::SEVERITY_PASS,
            'env.trusted_hosts' => SecurityFinding::SEVERITY_MEDIUM,
            'headers.enabled' => SecurityFinding::SEVERITY_PASS,
            'headers.csp' => SecurityFinding::SEVERITY_MEDIUM,
            'headers.hsts' => SecurityFinding::SEVERITY_MEDIUM,
            'waf.mode' => SecurityFinding::SEVERITY_MEDIUM,
            'flood.enabled' => SecurityFinding::SEVERITY_PASS,
            'flood.autoban' => SecurityFinding::SEVERITY_PASS,
            'waf.allowlist' => SecurityFinding::SEVERITY_LOW,
            'telemetry.security' => SecurityFinding::SEVERITY_MEDIUM,
            'password.length' => SecurityFinding::SEVERITY_PASS,
            'password.breach' => SecurityFinding::SEVERITY_PASS,
            'twofactor.enabled' => SecurityFinding::SEVERITY_MEDIUM,
            'session.idle' => SecurityFinding::SEVERITY_LOW,
            'settings.secrets' => SecurityFinding::SEVERITY_PASS,
            'users.admins' => SecurityFinding::SEVERITY_LOW,
        ], $this->bySeverity($findings));

        $summary = $auditor->summary($findings);
        self::assertSame(0, $summary[SecurityFinding::SEVERITY_CRITICAL]);
        self::assertSame(0, $summary[SecurityFinding::SEVERITY_HIGH]);
        self::assertSame(6, $summary[SecurityFinding::SEVERITY_MEDIUM]);
        self::assertSame(3, $summary[SecurityFinding::SEVERITY_LOW]);
        self::assertSame(7, $summary[SecurityFinding::SEVERITY_PASS]);

        // 6 medium (6 each) + 3 low (2 each) = 42 points off.
        self::assertSame(58, $auditor->score($findings));
    }

    /**
     * Reporting a pass the auditor cannot substantiate is worse than reporting
     * nothing, so the two request-dependent checks are absent from a console run
     * rather than reported as passing.
     */
    public function testRequestDependentChecksAreSkippedWithoutARequest(): void
    {
        $ids = array_keys($this->bySeverity($this->auditor()->run()));

        self::assertNotContains('env.https', $ids);
        self::assertNotContains('env.trusted_proxies', $ids);
    }

    public function testHttpsAndProxyChecksAppearOnceThereIsARequest(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('https://example.test/aacp/security'));

        $severities = $this->bySeverity($this->auditor(stack: $stack)->run());

        self::assertSame(SecurityFinding::SEVERITY_PASS, $severities['env.https']);
        self::assertSame(SecurityFinding::SEVERITY_PASS, $severities['env.trusted_proxies']);
    }

    public function testPlainHttpIsHighInProductionAndLowElsewhere(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('http://example.test/aacp/security'));

        self::assertSame(
            SecurityFinding::SEVERITY_HIGH,
            $this->bySeverity($this->auditor(stack: $stack, environment: 'prod')->run())['env.https'],
        );

        $devStack = new RequestStack();
        $devStack->push(Request::create('http://example.test/aacp/security'));

        self::assertSame(
            SecurityFinding::SEVERITY_LOW,
            $this->bySeverity($this->auditor(stack: $devStack, environment: 'dev')->run())['env.https'],
        );
    }

    /**
     * A forwarded-for header arriving while no proxy is trusted means every ban
     * and every rate limit is keyed on the proxy instead of the visitor.
     */
    public function testAForwardedHeaderWithoutATrustedProxyIsHigh(): void
    {
        $request = Request::create('https://example.test/aacp/security');
        $request->headers->set('X-Forwarded-For', '203.0.113.9');

        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame(
            SecurityFinding::SEVERITY_HIGH,
            $this->bySeverity($this->auditor(stack: $stack)->run())['env.trusted_proxies'],
        );
    }

    public function testDebugModeInProductionIsCritical(): void
    {
        $severities = $this->bySeverity($this->auditor(debug: true, environment: 'prod')->run());

        self::assertSame(SecurityFinding::SEVERITY_CRITICAL, $severities['env.debug']);
    }

    public function testDebugModeOutsideProductionIsNotAFinding(): void
    {
        $severities = $this->bySeverity($this->auditor(debug: true, environment: 'dev')->run());

        self::assertSame(SecurityFinding::SEVERITY_PASS, $severities['env.debug']);
    }

    public function testDisablingTheHeaderLayerIsCriticalAndShortCircuitsTheHeaderChecks(): void
    {
        $severities = $this->bySeverity($this->auditor(['security.headers_enabled' => false])->run());

        self::assertSame(SecurityFinding::SEVERITY_CRITICAL, $severities['headers.enabled']);
        self::assertArrayNotHasKey('headers.csp', $severities);
        self::assertArrayNotHasKey('headers.hsts', $severities);
    }

    public function testCspSeverityTracksTheMode(): void
    {
        foreach ([
            'off' => SecurityFinding::SEVERITY_HIGH,
            'report' => SecurityFinding::SEVERITY_MEDIUM,
            'balanced' => SecurityFinding::SEVERITY_LOW,
            'strict' => SecurityFinding::SEVERITY_PASS,
        ] as $mode => $expected) {
            self::assertSame(
                $expected,
                $this->bySeverity($this->auditor(['security.csp_mode' => $mode])->run())['headers.csp'],
                $mode,
            );
        }
    }

    public function testHstsSeverityTracksTheMaxAge(): void
    {
        foreach ([
            0 => SecurityFinding::SEVERITY_MEDIUM,
            86400 => SecurityFinding::SEVERITY_LOW,
            15552000 => SecurityFinding::SEVERITY_PASS,
            31536000 => SecurityFinding::SEVERITY_PASS,
        ] as $maxAge => $expected) {
            self::assertSame(
                $expected,
                $this->bySeverity($this->auditor(['security.hsts_max_age' => $maxAge])->run())['headers.hsts'],
                (string) $maxAge,
            );
        }
    }

    public function testWafSeverityTracksTheMode(): void
    {
        foreach ([
            'off' => SecurityFinding::SEVERITY_HIGH,
            'detect' => SecurityFinding::SEVERITY_MEDIUM,
            'block' => SecurityFinding::SEVERITY_PASS,
        ] as $mode => $expected) {
            self::assertSame(
                $expected,
                $this->bySeverity($this->auditor(['security.waf_mode' => $mode])->run())['waf.mode'],
                $mode,
            );
        }
    }

    /**
     * Regression: the auditor read the raw setting, so anything the match did not
     * recognise landed in the default arm and was reported as a PASS for blocking
     * mode — while RequestGuardSubscriber, which normalises the same value, was
     * actually running in detect. The auditor exists precisely to not do that.
     */
    public function testAnUnrecognisedWafModeIsAuditedAsTheModeThatActuallyRuns(): void
    {
        foreach (['blockk', 'BLOCK', 'enforce', '', 'true'] as $stored) {
            self::assertSame(
                SecurityFinding::SEVERITY_MEDIUM,
                $this->bySeverity($this->auditor(['security.waf_mode' => $stored])->run())['waf.mode'],
                var_export($stored, true),
            );
        }
    }

    public function testTurningOffTheFloodLimiterIsCritical(): void
    {
        $severities = $this->bySeverity($this->auditor(['security.flood_enabled' => false])->run());

        self::assertSame(SecurityFinding::SEVERITY_CRITICAL, $severities['flood.enabled']);
    }

    public function testDisablingEscalationIsAMediumFinding(): void
    {
        $severities = $this->bySeverity($this->auditor(['security.autoban_after_lockouts' => 0])->run());

        self::assertSame(SecurityFinding::SEVERITY_MEDIUM, $severities['flood.autoban']);
    }

    public function testAPopulatedAllowlistPasses(): void
    {
        $severities = $this->bySeverity($this->auditor(['security.ip_allowlist' => '203.0.113.0/24'])->run());

        self::assertSame(SecurityFinding::SEVERITY_PASS, $severities['waf.allowlist']);
    }

    public function testTwoFactorSeverityTracksEnforcement(): void
    {
        self::assertSame(
            SecurityFinding::SEVERITY_HIGH,
            $this->bySeverity($this->auditor(['security.twofactor_enabled' => false])->run())['twofactor.enabled'],
        );
        self::assertSame(
            SecurityFinding::SEVERITY_MEDIUM,
            $this->bySeverity($this->auditor(['security.twofactor_enforce_privileged' => false])->run())['twofactor.enabled'],
        );
        self::assertSame(
            SecurityFinding::SEVERITY_PASS,
            $this->bySeverity($this->auditor(['security.twofactor_enforce_privileged' => true])->run())['twofactor.enabled'],
        );
    }

    public function testAShortMinimumPasswordLengthIsAMediumFinding(): void
    {
        self::assertSame(
            SecurityFinding::SEVERITY_MEDIUM,
            $this->bySeverity($this->auditor(['security.password_min_length' => 8])->run())['password.length'],
        );
        self::assertSame(
            SecurityFinding::SEVERITY_PASS,
            $this->bySeverity($this->auditor(['security.password_min_length' => 12])->run())['password.length'],
        );
    }

    public function testAnIdleSessionLimitPasses(): void
    {
        self::assertSame(
            SecurityFinding::SEVERITY_PASS,
            $this->bySeverity($this->auditor(['security.session_idle_minutes' => 30])->run())['session.idle'],
        );
    }

    public function testAHardenedConfigurationScoresHigherThanTheDefault(): void
    {
        $hardened = $this->auditor([
            'security.trusted_hosts' => 'example.test',
            'security.csp_mode' => 'strict',
            'security.hsts_max_age' => 31536000,
            'security.waf_mode' => 'block',
            'security.ip_allowlist' => '203.0.113.0/24',
            'telemetry.security_enabled' => true,
            'security.twofactor_enforce_privileged' => true,
            'security.session_idle_minutes' => 30,
        ]);
        $findings = $hardened->run();

        // Only users.admins is left, and only because no admin exists in the
        // throwaway database.
        self::assertSame(['users.admins'], array_keys(array_filter(
            $this->bySeverity($findings),
            static fn (string $severity): bool => $severity !== SecurityFinding::SEVERITY_PASS,
        )));
        self::assertSame(98, $hardened->score($findings));
    }

    public function testScoreIsClampedToZeroAndOneHundred(): void
    {
        $auditor = $this->auditor();

        self::assertSame(100, $auditor->score([]));
        self::assertSame(0, $auditor->score(array_fill(0, 10, new SecurityFinding(
            'x',
            SecurityFinding::SEVERITY_CRITICAL,
            'title',
            'detail',
        ))));
    }

    public function testFindingPenaltiesAreOrderedBySeverity(): void
    {
        $penalty = static fn (string $severity): int => (new SecurityFinding('x', $severity, 't', 'd'))->penalty();

        self::assertSame(25, $penalty(SecurityFinding::SEVERITY_CRITICAL));
        self::assertSame(12, $penalty(SecurityFinding::SEVERITY_HIGH));
        self::assertSame(6, $penalty(SecurityFinding::SEVERITY_MEDIUM));
        self::assertSame(2, $penalty(SecurityFinding::SEVERITY_LOW));
        self::assertSame(0, $penalty(SecurityFinding::SEVERITY_PASS));
        self::assertSame(0, $penalty('not-a-severity'));
    }

    public function testPassFindingsAreMarkedAsSuch(): void
    {
        self::assertTrue(SecurityFinding::pass('x', 't', 'd')->isPass());
        self::assertFalse((new SecurityFinding('x', SecurityFinding::SEVERITY_LOW, 't', 'd'))->isPass());
    }

    public function testEveryFindingCarriesTranslationKeysRatherThanProse(): void
    {
        foreach ($this->auditor()->run() as $finding) {
            self::assertStringStartsWith('aacp.security.audit.', $finding->titleKey, $finding->id);
            self::assertStringStartsWith('aacp.security.audit.', $finding->detailKey, $finding->id);
            self::assertNotSame('', $finding->id);
        }
    }
}
