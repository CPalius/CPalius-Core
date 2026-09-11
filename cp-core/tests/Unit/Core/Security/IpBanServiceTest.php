<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Security\Service\IpBanService;
use App\Core\Security\Service\IpMatcher;
use App\Tests\Unit\Core\Security\Support\ArraySettings;
use App\Tests\Unit\Core\Security\Support\SecurityTestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpBanService::class)]
final class IpBanServiceTest extends TestCase
{
    private Connection $connection;
    private ArraySettings $settings;
    private IpBanService $bans;

    protected function setUp(): void
    {
        $this->connection = SecurityTestDatabase::connect();
        $this->settings = new ArraySettings();
        $this->bans = new IpBanService($this->connection, new IpMatcher(), $this->settings);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testALiteralBanBlocksExactlyThatAddress(): void
    {
        self::assertTrue($this->bans->ban('1.2.3.4'));

        self::assertTrue($this->bans->isBanned('1.2.3.4'));
        self::assertFalse($this->bans->isBanned('1.2.3.5'));
    }

    public function testARangeBanBlocksEveryAddressInIt(): void
    {
        self::assertTrue($this->bans->ban('10.0.0.0/24'));

        self::assertTrue($this->bans->isBanned('10.0.0.1'));
        self::assertTrue($this->bans->isBanned('10.0.0.255'));
        self::assertFalse($this->bans->isBanned('10.0.1.1'));
    }

    public function testAnInvalidPatternIsRefused(): void
    {
        self::assertFalse($this->bans->ban('not-an-address'));
        self::assertFalse($this->bans->ban(''));
        self::assertFalse($this->bans->ban('1.2.3.0/99'));
        self::assertSame(0, $this->bans->stats()['total']);
    }

    public function testAnEmptyAddressIsNeverBanned(): void
    {
        $this->bans->ban('1.2.3.4');

        self::assertFalse($this->bans->isBanned(''));
        self::assertFalse($this->bans->isBanned('   '));
    }

    // --- allowlist invariants ---------------------------------------------

    public function testTheAllowlistOverridesAnExistingBan(): void
    {
        $this->bans->ban('10.0.0.0/8');
        self::assertTrue($this->bans->isBanned('10.1.2.3'));

        $this->settings->put('security.ip_allowlist', '10.1.2.3');

        self::assertFalse($this->bans->isBanned('10.1.2.3'), 'The allowlist always wins.');
        self::assertTrue($this->bans->isBanned('10.1.2.4'), 'Only the allowlisted address is spared.');
    }

    public function testAnAllowlistedLiteralCannotBeBanned(): void
    {
        $this->settings->put('security.ip_allowlist', "203.0.113.7\n10.0.0.0/8");

        self::assertFalse($this->bans->ban('203.0.113.7'));
        self::assertSame(0, $this->bans->stats()['total']);
    }

    /**
     * Regression: the check used to skip anything containing a '/', so a range
     * covering the operator's own allowlisted address was accepted into the ban
     * table — a row that reads as a lockout and that a later allowlist edit would
     * silently activate.
     */
    public function testARangeCoveringAnAllowlistedAddressCannotBeBanned(): void
    {
        $this->settings->put('security.ip_allowlist', '203.0.113.7');

        self::assertFalse($this->bans->ban('203.0.113.0/24'));
        self::assertFalse($this->bans->ban('0.0.0.0/0'));
        self::assertSame(0, $this->bans->stats()['total']);

        // A range that does not cover it is still bannable.
        self::assertTrue($this->bans->ban('198.51.100.0/24'));
    }

    public function testAllowlistParsesOperatorTypedSeparators(): void
    {
        $this->settings->put('security.ip_allowlist', " 1.2.3.4 , 10.0.0.0/8 ;\n nonsense \n2001:db8::/32");

        self::assertSame(['1.2.3.4', '10.0.0.0/8', '2001:db8::/32'], $this->bans->allowlist());
        self::assertTrue($this->bans->isAllowlisted('10.9.9.9'));
        self::assertTrue($this->bans->isAllowlisted('2001:db8::99'));
        self::assertFalse($this->bans->isAllowlisted('11.9.9.9'));
    }

    // --- address spelling --------------------------------------------------

    /**
     * Regression: a dual-stack listener reports IPv4 clients in mapped form. The
     * literal lookup was a plain string equality, so the same host arriving as
     * "::ffff:1.2.3.4" walked straight past a ban on "1.2.3.4".
     */
    public function testAMappedIpv4ClientCannotEvadeALiteralBan(): void
    {
        $this->bans->ban('1.2.3.4');

        self::assertTrue($this->bans->isBanned('::ffff:1.2.3.4'));
    }

    public function testAMappedIpv4ClientCannotEvadeARangeBan(): void
    {
        $this->bans->ban('1.2.3.0/24');

        self::assertTrue($this->bans->isBanned('::ffff:1.2.3.9'));
    }

    public function testAnExpandedIpv6ClientCannotEvadeACompressedBan(): void
    {
        $this->bans->ban('2001:db8::1');

        self::assertTrue($this->bans->isBanned('2001:0db8:0000:0000:0000:0000:0000:0001'));
    }

    public function testABanTypedInAnExpandedFormStillMatchesTheCompressedClient(): void
    {
        $this->bans->ban('2001:0db8:0000:0000:0000:0000:0000:0001');

        self::assertTrue($this->bans->isBanned('2001:db8::1'));
    }

    public function testBansAreStoredCanonically(): void
    {
        $this->bans->ban('2001:0DB8:0000:0000:0000:0000:0000:0001');
        $this->bans->ban('::ffff:198.51.100.0/24');

        $stored = $this->connection->fetchFirstColumn('SELECT ip_address FROM cp_banned_ips ORDER BY id');

        self::assertSame(['2001:db8::1', '198.51.100.0/24'], $stored);
    }

    // --- expiry ------------------------------------------------------------

    public function testAnExpiredBanNoLongerMatches(): void
    {
        $this->bans->ban('1.2.3.4', null, 60);
        self::assertTrue($this->bans->isBanned('1.2.3.4'));

        $this->connection->executeStatement(
            'UPDATE cp_banned_ips SET expires_at = :past',
            ['past' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')],
        );

        self::assertFalse($this->bans->isBanned('1.2.3.4'));
    }

    public function testNullOrZeroMinutesMeansPermanent(): void
    {
        $this->bans->ban('1.2.3.4', null, null);
        $this->bans->ban('1.2.3.5', null, 0);

        $expiries = $this->connection->fetchFirstColumn('SELECT expires_at FROM cp_banned_ips ORDER BY id');

        self::assertSame([null, null], $expiries);
    }

    public function testPurgeExpiredRemovesOnlyExpiredRows(): void
    {
        $this->bans->ban('1.2.3.4');
        $this->bans->ban('1.2.3.5', null, 60);
        $this->bans->ban('1.2.3.6', null, 60);

        $this->connection->executeStatement(
            'UPDATE cp_banned_ips SET expires_at = :past WHERE ip_address = :ip',
            ['past' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'ip' => '1.2.3.6'],
        );

        self::assertSame(1, $this->bans->purgeExpired());
        self::assertSame(2, $this->bans->stats()['total']);
    }

    /**
     * The automatic escalation path must never be able to weaken what an operator
     * decided by hand.
     */
    public function testAnAutomaticBanNeverShortensAManualOne(): void
    {
        $this->bans->ban('1.2.3.4', 1, 1440, 'manual reason', IpBanService::SOURCE_MANUAL);
        $long = $this->connection->fetchOne('SELECT expires_at FROM cp_banned_ips WHERE ip_address = :ip', ['ip' => '1.2.3.4']);

        self::assertTrue($this->bans->ban('1.2.3.4', null, 5, 'waf:auto', IpBanService::SOURCE_AUTO));

        self::assertSame(
            $long,
            $this->connection->fetchOne('SELECT expires_at FROM cp_banned_ips WHERE ip_address = :ip', ['ip' => '1.2.3.4']),
        );
    }

    public function testAnAutomaticBanExtendsAShorterExistingOne(): void
    {
        $this->bans->ban('1.2.3.4', null, 5, null, IpBanService::SOURCE_AUTO);
        $short = $this->connection->fetchOne('SELECT expires_at FROM cp_banned_ips WHERE ip_address = :ip', ['ip' => '1.2.3.4']);

        self::assertTrue($this->bans->ban('1.2.3.4', null, 1440, null, IpBanService::SOURCE_AUTO));

        $extended = $this->connection->fetchOne('SELECT expires_at FROM cp_banned_ips WHERE ip_address = :ip', ['ip' => '1.2.3.4']);
        self::assertGreaterThan($short, $extended);
    }

    public function testATemporaryBanNeverOverwritesAPermanentOne(): void
    {
        $this->bans->ban('1.2.3.4', null, null, null, IpBanService::SOURCE_MANUAL);

        self::assertTrue($this->bans->ban('1.2.3.4', null, 5, null, IpBanService::SOURCE_AUTO));

        self::assertNull(
            $this->connection->fetchOne('SELECT expires_at FROM cp_banned_ips WHERE ip_address = :ip', ['ip' => '1.2.3.4']),
        );
    }

    public function testAPermanentBanOverwritesATemporaryOne(): void
    {
        $this->bans->ban('1.2.3.4', null, 5, null, IpBanService::SOURCE_AUTO);

        self::assertTrue($this->bans->ban('1.2.3.4', null, null, null, IpBanService::SOURCE_MANUAL));

        self::assertNull(
            $this->connection->fetchOne('SELECT expires_at FROM cp_banned_ips WHERE ip_address = :ip', ['ip' => '1.2.3.4']),
        );
    }

    public function testRebanningDoesNotCreateASecondRow(): void
    {
        $this->bans->ban('1.2.3.4', null, 10);
        $this->bans->ban('1.2.3.4', null, 20);
        $this->bans->ban('1.2.3.4', null, 30);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_banned_ips'));
    }

    // --- bookkeeping -------------------------------------------------------

    public function testFindMatchReturnsTheRowIdAndCountsAHit(): void
    {
        $this->bans->ban('1.2.3.0/24');

        $id = $this->bans->findMatch('1.2.3.4');
        self::assertNotNull($id);
        $this->bans->findMatch('1.2.3.5');

        $row = $this->connection->fetchAssociative('SELECT hit_count, last_hit_at FROM cp_banned_ips WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertSame(2, (int) $row['hit_count']);
        self::assertNotNull($row['last_hit_at']);
    }

    public function testUnbanRemovesTheRowInAnySpelling(): void
    {
        $this->bans->ban('2001:0db8:0000:0000:0000:0000:0000:0001');

        self::assertTrue($this->bans->unban('2001:0db8:0000:0000:0000:0000:0000:0001'));
        self::assertFalse($this->bans->isBanned('2001:db8::1'));
        self::assertFalse($this->bans->unban('2001:db8::1'), 'Nothing left to remove.');
    }

    public function testUnbanByIdRemovesExactlyOneRow(): void
    {
        $this->bans->ban('1.2.3.4');
        $this->bans->ban('1.2.3.5');
        $id = $this->bans->findMatch('1.2.3.4');
        self::assertNotNull($id);

        self::assertTrue($this->bans->unbanById($id));
        self::assertFalse($this->bans->unbanById($id));
        self::assertFalse($this->bans->isBanned('1.2.3.4'));
        self::assertTrue($this->bans->isBanned('1.2.3.5'));
    }

    public function testStatsSeparateAutomaticAndExpiringBans(): void
    {
        $this->bans->ban('1.2.3.4', null, null, null, IpBanService::SOURCE_MANUAL);
        $this->bans->ban('1.2.3.5', null, 60, null, IpBanService::SOURCE_AUTO);
        $this->bans->ban('1.2.3.6', null, 60, null, IpBanService::SOURCE_MANUAL);

        self::assertSame(['total' => 3, 'automatic' => 1, 'expiring' => 2], $this->bans->stats());
    }

    public function testListBansIsOrderedAndBounded(): void
    {
        $this->bans->ban('1.2.3.4');
        $this->bans->ban('1.2.3.5');

        $rows = $this->bans->listBans(1);

        self::assertCount(1, $rows);
        self::assertArrayHasKey('ip_address', $rows[0]);
        self::assertArrayHasKey('banned_by_email', $rows[0]);
    }

    /**
     * The perimeter degrades, it never raises: a broken connection must read as
     * "not banned" rather than as a 500 on every request.
     */
    public function testEveryQueryDegradesQuietlyWhenTheConnectionIsGone(): void
    {
        $this->bans->ban('1.2.3.4');

        // Pulling the table out from under the service is the closest a unit test
        // gets to "the database went away".
        $this->connection->executeStatement('DROP TABLE cp_banned_ips');

        self::assertFalse($this->bans->isBanned('1.2.3.4'));
        self::assertNull($this->bans->findMatch('1.2.3.4'));
        self::assertFalse($this->bans->ban('9.9.9.9'));
        self::assertFalse($this->bans->unban('1.2.3.4'));
        self::assertFalse($this->bans->unbanById(1));
        self::assertSame(0, $this->bans->purgeExpired());
        self::assertSame([], $this->bans->listBans());
        self::assertSame(['total' => 0, 'automatic' => 0, 'expiring' => 0], $this->bans->stats());
    }
}
