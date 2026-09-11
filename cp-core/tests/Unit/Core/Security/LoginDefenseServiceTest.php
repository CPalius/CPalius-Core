<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\CaptchaService;
use App\Core\Security\Flood\FloodService;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Security\Service\IpBanService;
use App\Core\Security\Service\IpMatcher;
use App\Core\Security\Service\LoginDefenseService;
use App\Core\Security\Service\SecurityEventRecorder;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use App\Tests\Unit\Core\Security\Support\SecurityClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LoginDefenseService::class)]
#[Group('time-sensitive')]
final class LoginDefenseServiceTest extends TestCase
{
    private const IP = '203.0.113.9';
    private const ACCOUNT = 'ali@example.com';

    private Connection $connection;
    private ArraySettings $settings;
    private FloodService $flood;
    private IpBanService $bans;
    private LoginDefenseService $defense;

    public static function setUpBeforeClass(): void
    {
        SecurityClock::install();
    }

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();
        $this->settings = new ArraySettings([
            'security.flood_enabled' => true,
            'security.login_user_limit' => 3,
            'security.login_ip_limit' => 6,
            'security.login_window_minutes' => 15,
            'security.lockout_minutes' => 15,
            'security.autoban_after_lockouts' => 3,
            'security.waf_autoban_minutes' => 1440,
        ]);

        $this->flood = new FloodService(new ArrayAdapter(storeSerialized: false), $this->settings);
        $this->bans = new IpBanService($this->connection, new IpMatcher(), $this->settings);

        $recorder = new SecurityEventRecorder(
            $this->createMock(TelemetryLogRepository::class),
            new RequestStack(),
            $this->createMock(Security::class),
            new NullLogger(),
        );

        $this->defense = new LoginDefenseService(
            $this->flood,
            $this->bans,
            $this->settings,
            $recorder,
            new CaptchaService($this->settings),
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function failLogin(int $times, string $ip = self::IP, string $account = self::ACCOUNT): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->defense->registerFailure($ip, $account);
        }
    }

    // --- per-account counter -----------------------------------------------

    public function testAnAccountIsLockedOnceItsOwnLimitIsReached(): void
    {
        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));

        $this->failLogin(2);
        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));

        $this->failLogin(1);
        self::assertTrue($this->defense->isBlocked(self::IP, self::ACCOUNT));
    }

    public function testTheAccountLockFollowsTheAccountNotTheHost(): void
    {
        $this->failLogin(3);

        self::assertTrue($this->defense->isBlocked('198.51.100.4', self::ACCOUNT));
        self::assertFalse($this->defense->isBlocked(self::IP, 'veli@example.com'));
    }

    public function testTheAccountCounterIsCaseInsensitive(): void
    {
        $this->failLogin(3, account: 'Ali@Example.COM');

        self::assertTrue($this->defense->isBlocked(self::IP, 'ali@example.com'));
    }

    public function testTheLockLiftsWhenItExpires(): void
    {
        $this->failLogin(3);
        self::assertTrue($this->defense->isBlocked(self::IP, self::ACCOUNT));

        sleep(15 * 60 + 1);

        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));
    }

    public function testLockedUntilReportsTheFurthestOfTheTwoLocks(): void
    {
        $this->failLogin(3);

        $until = $this->defense->lockedUntil(self::IP, self::ACCOUNT);
        self::assertNotNull($until);
        self::assertSame(time() + 15 * 60, $until);
    }

    public function testAnEmptyIdentifierOrIpIsSimplyNotCounted(): void
    {
        $this->defense->registerFailure('', '');

        self::assertNull($this->defense->lockedUntil('', ''));
        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));
    }

    // --- per-IP counter -----------------------------------------------------

    public function testTheHostCounterCatchesAnAttackerRotatingAccounts(): void
    {
        // Six different accounts, one failure each: no account counter ever trips,
        // but the host has burned its whole allowance.
        for ($i = 1; $i <= 6; ++$i) {
            $this->defense->registerFailure(self::IP, 'victim'.$i.'@example.com');
        }

        self::assertTrue($this->defense->isBlocked(self::IP, 'victim7@example.com'));
        self::assertFalse($this->defense->isBlocked('198.51.100.4', 'victim7@example.com'));
    }

    public function testSuccessClearsBothCounters(): void
    {
        $this->failLogin(2);
        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));

        $this->defense->registerSuccess(self::IP, self::ACCOUNT);

        // Counters are back to zero, so a fresh run of two failures still does not lock.
        $this->failLogin(2);
        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));
    }

    // --- escalation ---------------------------------------------------------

    /**
     * Regression: every failed attempt arriving while the lock was already held
     * re-locked and re-escalated, so the "repeated lockouts" counter counted
     * attempts rather than lockouts. Three extra tries against an already-locked
     * account were enough to auto-ban the source — behind a shared NAT, one
     * forgetful employee taking the whole office offline for a day.
     */
    public function testHammeringAnAlreadyLockedAccountDoesNotBanTheHost(): void
    {
        $this->failLogin(3);
        self::assertTrue($this->defense->isBlocked(self::IP, self::ACCOUNT));

        // Ten more attempts against the locked account.
        $this->failLogin(10);

        self::assertFalse($this->bans->isBanned(self::IP), 'One lockout must not escalate to a ban.');
    }

    public function testThreeSeparateLockoutsDoEscalateToATimedBan(): void
    {
        for ($round = 1; $round <= 3; ++$round) {
            $this->failLogin(3, account: 'victim'.$round.'@example.com');
            // Let each lock expire so the next round is a genuinely new lockout.
            sleep(15 * 60 + 1);
        }

        self::assertTrue($this->bans->isBanned(self::IP));

        $row = $this->connection->fetchAssociative('SELECT source, reason, expires_at FROM cp_banned_ips');
        self::assertIsArray($row);
        self::assertSame(IpBanService::SOURCE_AUTO, $row['source']);
        self::assertSame('login_bruteforce', $row['reason']);
        self::assertNotNull($row['expires_at'], 'An automatic ban is always timed.');
    }

    public function testTwoLockoutsAreNotYetEnoughToBan(): void
    {
        for ($round = 1; $round <= 2; ++$round) {
            $this->failLogin(3, account: 'victim'.$round.'@example.com');
            sleep(15 * 60 + 1);
        }

        self::assertFalse($this->bans->isBanned(self::IP));
    }

    /**
     * Regression: when both counters tripped in the same request, escalate() ran
     * twice and the lockout counter jumped by two instead of one.
     */
    public function testAccountAndHostLockingTogetherCountsAsOneLockout(): void
    {
        // With both limits at 3, the third failure trips the account and the host
        // counter in the same call.
        $this->settings->put('security.login_ip_limit', 3);

        $this->failLogin(3);
        self::assertTrue($this->defense->isBlocked(self::IP, self::ACCOUNT));

        self::assertSame(
            1,
            $this->flood->count(FloodService::EVENT_LOCKOUT_COUNT, self::IP, 86400),
            'One lockout event, not two.',
        );
    }

    public function testEscalationCanBeTurnedOff(): void
    {
        $this->settings->put('security.autoban_after_lockouts', 0);

        for ($round = 1; $round <= 5; ++$round) {
            $this->failLogin(3, account: 'victim'.$round.'@example.com');
            sleep(15 * 60 + 1);
        }

        self::assertFalse($this->bans->isBanned(self::IP));
    }

    public function testAnAllowlistedHostIsNeverAutoBanned(): void
    {
        $this->settings->put('security.ip_allowlist', self::IP);

        for ($round = 1; $round <= 5; ++$round) {
            $this->failLogin(3, account: 'victim'.$round.'@example.com');
            sleep(15 * 60 + 1);
        }

        self::assertFalse($this->bans->isBanned(self::IP));
        self::assertSame(0, $this->bans->stats()['total'], 'The ban was refused, not merely overridden.');
    }

    // --- captcha ------------------------------------------------------------

    public function testNoCaptchaIsRequestedWhenNoProviderIsConfigured(): void
    {
        $this->failLogin(5);

        self::assertFalse($this->defense->captchaRequiredOnLogin(self::IP));
    }

    public function testCaptchaKicksInAtHalfTheHostAllowanceOnceAProviderIsReady(): void
    {
        $this->settings->put('security.captcha_provider', CaptchaService::PROVIDER_TURNSTILE);
        $this->settings->put('security.turnstile_site_key', 'site');
        $this->settings->put('security.turnstile_secret_key', 'secret');

        // Host limit 6, so the threshold is 3.
        $this->defense->registerFailure(self::IP, 'a@example.com');
        $this->defense->registerFailure(self::IP, 'b@example.com');
        self::assertFalse($this->defense->captchaRequiredOnLogin(self::IP));

        $this->defense->registerFailure(self::IP, 'c@example.com');
        self::assertTrue($this->defense->captchaRequiredOnLogin(self::IP));
    }

    public function testCaptchaIsAlwaysRequiredWhenTheOperatorTurnedItOn(): void
    {
        $this->settings->put('security.captcha_on_login', true);
        $this->settings->put('security.captcha_provider', CaptchaService::PROVIDER_TURNSTILE);
        $this->settings->put('security.turnstile_site_key', 'site');
        $this->settings->put('security.turnstile_secret_key', 'secret');

        self::assertTrue($this->defense->captchaRequiredOnLogin(self::IP));
    }

    public function testNoCaptchaWithoutAnIp(): void
    {
        self::assertFalse($this->defense->captchaRequiredOnLogin(''));
    }

    // --- misconfiguration ---------------------------------------------------

    public function testNonsensicalLimitsFallBackToTheDefaults(): void
    {
        $this->settings->put('security.login_user_limit', 0);
        $this->settings->put('security.login_ip_limit', -5);
        $this->settings->put('security.lockout_minutes', 0);

        // Defaults are 5 / 20 / 15 minutes; four failures must not lock yet.
        $this->failLogin(4);
        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));

        $this->failLogin(1);
        self::assertTrue($this->defense->isBlocked(self::IP, self::ACCOUNT));
        self::assertSame(time() + 15 * 60, $this->defense->lockedUntil(self::IP, self::ACCOUNT));
    }

    public function testDisablingTheFloodServiceDisablesTheDefenceEntirely(): void
    {
        $this->settings->put('security.flood_enabled', false);

        $this->failLogin(20);

        self::assertFalse($this->defense->isBlocked(self::IP, self::ACCOUNT));
    }
}
