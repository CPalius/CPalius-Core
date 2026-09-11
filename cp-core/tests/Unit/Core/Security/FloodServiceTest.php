<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Flood\FloodService;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use App\Tests\Unit\Core\Security\Support\ExplodingCachePool;
use App\Tests\Unit\Core\Security\Support\ExplodingSettings;
use App\Tests\Unit\Core\Security\Support\PoisonedCachePool;

/**
 * The "time-sensitive" group makes the Symfony PHPUnit bridge install its clock
 * mock for the App namespace (configured in phpunit.xml.dist), so time() inside
 * FloodService can be advanced deterministically — which is the only way to prove
 * that the window really slides instead of resetting.
 */
#[CoversClass(FloodService::class)]
#[Group('time-sensitive')]
final class FloodServiceTest extends TestCase
{
    private const EVENT = FloodService::EVENT_LOGIN_IP;
    private const ID = 'ali@example.com';

    private ArrayAdapter $cache;
    private ArraySettings $settings;
    private FloodService $flood;

    public static function setUpBeforeClass(): void
    {
        SecurityClock::install();
    }

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(storeSerialized: false);
        $this->settings = new ArraySettings(['security.flood_enabled' => true]);
        $this->flood = new FloodService($this->cache, $this->settings);
    }

    public function testUnderTheLimitIsAllowed(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
            $this->flood->register(self::EVENT, self::ID, 3600);
        }

        self::assertSame(4, $this->flood->count(self::EVENT, self::ID, 3600));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    public function testTheAttemptThatReachesTheLimitIsTheLastAllowedOne(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600), 'attempt '.($i + 1));
            $this->flood->register(self::EVENT, self::ID, 3600);
        }

        // Five failures recorded, the sixth attempt is refused.
        self::assertSame(5, $this->flood->count(self::EVENT, self::ID, 3600));
        self::assertFalse($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    public function testOverTheLimitStaysRefused(): void
    {
        for ($i = 0; $i < 9; ++$i) {
            $this->flood->register(self::EVENT, self::ID, 3600);
        }

        self::assertSame(9, $this->flood->count(self::EVENT, self::ID, 3600));
        self::assertFalse($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    public function testRegisterReturnsTheRunningCount(): void
    {
        self::assertSame(1, $this->flood->register(self::EVENT, self::ID, 3600));
        self::assertSame(2, $this->flood->register(self::EVENT, self::ID, 3600));
        self::assertSame(3, $this->flood->register(self::EVENT, self::ID, 3600));
    }

    public function testHitsLeaveTheWindowOneByOneAsTimePasses(): void
    {
        $window = 100;

        $this->flood->register(self::EVENT, self::ID, $window);
        sleep(40);
        $this->flood->register(self::EVENT, self::ID, $window);
        sleep(40);
        $this->flood->register(self::EVENT, self::ID, $window);

        self::assertSame(3, $this->flood->count(self::EVENT, self::ID, $window));

        // t+101 relative to the first hit: it has aged out, the other two have not.
        sleep(21);
        self::assertSame(2, $this->flood->count(self::EVENT, self::ID, $window));

        sleep(40);
        self::assertSame(1, $this->flood->count(self::EVENT, self::ID, $window));

        sleep(40);
        self::assertSame(0, $this->flood->count(self::EVENT, self::ID, $window));
    }

    /**
     * A fixed counter window resets wholesale at the boundary, which lets an
     * attacker spend the full limit just before it and the full limit just after
     * — twice the limit inside one window's worth of time. A sliding window must
     * still refuse the attempt that straddles the boundary.
     */
    public function testWindowSlidesInsteadOfResettingAtTheBoundary(): void
    {
        $window = 60;
        $limit = 5;

        // The full allowance is spent at t+0.
        for ($i = 0; $i < $limit; ++$i) {
            $this->flood->register(self::EVENT, self::ID, $window);
        }
        self::assertFalse($this->flood->isAllowed(self::EVENT, self::ID, $limit, $window));

        // t+59, one second before those hits age out. A fixed counter keyed on
        // floor(t / window) would have rolled over somewhere in here and handed the
        // attacker a second full allowance inside the same sixty seconds.
        sleep(59);
        self::assertSame(5, $this->flood->count(self::EVENT, self::ID, $window));
        self::assertFalse(
            $this->flood->isAllowed(self::EVENT, self::ID, $limit, $window),
            'A sliding window must still see all five hits one second before they expire.',
        );

        // t+61: the hits are genuinely outside the window now.
        sleep(2);
        self::assertSame(0, $this->flood->count(self::EVENT, self::ID, $window));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, $limit, $window));
    }

    public function testALimitOfZeroOrLessIsNoLimitAtAll(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->flood->register(self::EVENT, self::ID, 3600);
        }

        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 0, 3600));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, -1, 3600));
    }

    public function testDisablingTheServiceAllowsEverythingAndRecordsNothing(): void
    {
        $this->settings->put('security.flood_enabled', false);

        self::assertSame(0, $this->flood->register(self::EVENT, self::ID, 3600));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 1, 3600));
        self::assertSame(0, $this->flood->count(self::EVENT, self::ID, 3600));
    }

    public function testCountersAreIsolatedPerEventAndPerIdentifier(): void
    {
        $this->flood->register(FloodService::EVENT_LOGIN_IP, '1.2.3.4', 3600);
        $this->flood->register(FloodService::EVENT_LOGIN_IP, '1.2.3.4', 3600);

        self::assertSame(2, $this->flood->count(FloodService::EVENT_LOGIN_IP, '1.2.3.4', 3600));
        self::assertSame(0, $this->flood->count(FloodService::EVENT_LOGIN_IP, '1.2.3.5', 3600));
        self::assertSame(0, $this->flood->count(FloodService::EVENT_LOGIN_USER, '1.2.3.4', 3600));
    }

    public function testIdentifiersAreCaseAndWhitespaceInsensitive(): void
    {
        $this->flood->register(self::EVENT, 'Ali@Example.COM', 3600);

        self::assertSame(1, $this->flood->count(self::EVENT, '  ali@example.com  ', 3600));
    }

    /**
     * The identifier must not survive into the cache keyspace in readable form:
     * e-mail addresses sitting in a shared Redis is a data leak on its own.
     */
    public function testIdentifiersAreHashedIntoTheCacheKey(): void
    {
        $this->flood->register(self::EVENT, self::ID, 3600);
        $this->flood->lock(self::EVENT, self::ID, 60);

        $keys = array_keys($this->cache->getValues());
        self::assertNotSame([], $keys);

        foreach ($keys as $key) {
            self::assertStringNotContainsString(self::ID, (string) $key);
            self::assertStringNotContainsString('example.com', (string) $key);
        }
    }

    public function testClearDropsTheAttemptWindow(): void
    {
        $this->flood->register(self::EVENT, self::ID, 3600);
        $this->flood->register(self::EVENT, self::ID, 3600);
        self::assertSame(2, $this->flood->count(self::EVENT, self::ID, 3600));

        $this->flood->clear(self::EVENT, self::ID);

        self::assertSame(0, $this->flood->count(self::EVENT, self::ID, 3600));
    }

    public function testLockRefusesEverythingUntilItExpires(): void
    {
        self::assertNull($this->flood->lockedUntil(self::EVENT, self::ID));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));

        $this->flood->lock(self::EVENT, self::ID, 120);

        $until = $this->flood->lockedUntil(self::EVENT, self::ID);
        self::assertNotNull($until);
        self::assertSame(time() + 120, $until);
        self::assertFalse($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));

        sleep(121);

        self::assertNull($this->flood->lockedUntil(self::EVENT, self::ID));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    public function testLockOfZeroOrLessSecondsIsIgnored(): void
    {
        $this->flood->lock(self::EVENT, self::ID, 0);
        self::assertNull($this->flood->lockedUntil(self::EVENT, self::ID));

        $this->flood->lock(self::EVENT, self::ID, -5);
        self::assertNull($this->flood->lockedUntil(self::EVENT, self::ID));
    }

    /**
     * Regression: clear() used to delete the lock key as well, so any code path
     * that reset counters also cancelled an active lockout — exactly what lock()'s
     * contract says must not happen.
     */
    public function testClearDoesNotLiftAnActiveLock(): void
    {
        $this->flood->register(self::EVENT, self::ID, 3600);
        $this->flood->lock(self::EVENT, self::ID, 900);

        $this->flood->clear(self::EVENT, self::ID);

        self::assertSame(0, $this->flood->count(self::EVENT, self::ID, 3600), 'Counters are cleared.');
        self::assertNotNull($this->flood->lockedUntil(self::EVENT, self::ID), 'The lock survives.');
        self::assertFalse($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    public function testUnlockLiftsTheLockExplicitly(): void
    {
        $this->flood->lock(self::EVENT, self::ID, 900);
        self::assertNotNull($this->flood->lockedUntil(self::EVENT, self::ID));

        $this->flood->unlock(self::EVENT, self::ID);

        self::assertNull($this->flood->lockedUntil(self::EVENT, self::ID));
        self::assertTrue($this->flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function failModes(): iterable
    {
        yield 'login-style fail open' => [true, true];
        yield 'anonymous-endpoint fail closed' => [false, false];
    }

    #[DataProvider('failModes')]
    public function testCacheOutageHonoursTheFailOpenFlag(bool $failOpen, bool $expected): void
    {
        $flood = new FloodService(new ExplodingCachePool(), $this->settings);

        self::assertSame($expected, $flood->isAllowed(self::EVENT, self::ID, 5, 3600, $failOpen));
    }

    public function testCacheOutageDefaultsToFailClosed(): void
    {
        $flood = new FloodService(new ExplodingCachePool(), $this->settings);

        self::assertFalse($flood->isAllowed(self::EVENT, self::ID, 5, 3600));
    }

    /**
     * Regression: enabled() reads the settings registry (and therefore the
     * database) and used to sit outside the try/catch, so a database blip raised
     * straight out of the limiter and 500'd the login form.
     */
    public function testASettingsFailureHonoursTheFailOpenFlagInsteadOfRaising(): void
    {
        $flood = new FloodService(new ArrayAdapter(), new ExplodingSettings());

        self::assertTrue($flood->isAllowed(self::EVENT, self::ID, 5, 3600, failOpen: true));
        self::assertFalse($flood->isAllowed(self::EVENT, self::ID, 5, 3600, failOpen: false));
    }

    public function testEveryWriteAndReadSurvivesACacheOutage(): void
    {
        $flood = new FloodService(new ExplodingCachePool(), $this->settings);

        self::assertSame(0, $flood->register(self::EVENT, self::ID, 3600));
        self::assertSame(0, $flood->count(self::EVENT, self::ID, 3600));
        self::assertNull($flood->lockedUntil(self::EVENT, self::ID));

        // None of these may raise.
        $flood->lock(self::EVENT, self::ID, 60);
        $flood->clear(self::EVENT, self::ID);
        $flood->unlock(self::EVENT, self::ID);

        // Kesintiden sonra servis hâlâ cevap veriyor olmalı: sessiz bir bozulma
        // "hata fırlatmadı" ile yetinen bir assertion tarafından yakalanamazdı.
        self::assertSame(0, $flood->count(self::EVENT, self::ID, 3600));
    }

    public function testCorruptCachePayloadIsIgnoredRatherThanTrusted(): void
    {
        // A cache entry from an older format, or a poisoned one: anything that is
        // not a list of integer timestamps must count as zero hits.
        $flood = new FloodService(new PoisonedCachePool(['not', 'timestamps', null, 3.5, time()]), $this->settings);

        // Only the one integer timestamp inside the window survives normalisation.
        self::assertSame(1, $flood->count(self::EVENT, self::ID, 3600));

        $stale = new FloodService(new PoisonedCachePool(['not', 'timestamps', 42]), $this->settings);
        self::assertSame(0, $stale->count(self::EVENT, self::ID, 3600), 'A timestamp older than the window is pruned.');

        $scalar = new FloodService(new PoisonedCachePool([]), $this->settings);
        self::assertSame(0, $scalar->count(self::EVENT, self::ID, 3600));
    }
}
