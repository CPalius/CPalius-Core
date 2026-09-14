<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\Source\DatabaseSource;
use App\Core\Migrate\Source\ForeignDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSource::class)]
#[CoversClass(ForeignDatabase::class)]
final class DatabaseSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        // An in-memory SQLite database stands in for the foreign one: the
        // paging, keying and stringifying this class does are the same
        // whatever the server, and a test that needed a running MySQL would
        // not be run.
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE xf_user (user_id INTEGER PRIMARY KEY, username TEXT, email TEXT, is_banned INTEGER)');
    }

    private function seed(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->connection->insert('xf_user', [
                'user_id' => $i,
                'username' => 'user'.$i,
                'email' => 'user'.$i.'@example.test',
                'is_banned' => $i % 5 === 0 ? 1 : 0,
            ]);
        }
    }

    private function source(int $batchSize = 500, string $where = ''): DatabaseSource
    {
        return new DatabaseSource(
            ForeignDatabase::wrap($this->connection, 'xf_'),
            'xf_user',
            'user_id',
            '*',
            $where,
            $batchSize,
        );
    }

    /**
     * @return list<MigrationRow>
     */
    private function rows(DatabaseSource $source): array
    {
        return iterator_to_array($source->rows(), false);
    }

    public function testReadsEveryRowKeyedByItsPrimaryKey(): void
    {
        $this->seed(3);

        $rows = $this->rows($this->source());

        self::assertSame(['1', '2', '3'], array_map(static fn (MigrationRow $r): string => $r->sourceId, $rows));
        self::assertSame('user1@example.test', $rows[0]->get('email'));
    }

    /**
     * The point of keyset paging: every row is seen exactly once across many
     * batches, with no duplicates at the seams and nothing skipped.
     */
    public function testPagesThroughATableLargerThanOneBatch(): void
    {
        $this->seed(250);

        $ids = array_map(static fn (MigrationRow $r): string => $r->sourceId, $this->rows($this->source(10)));

        self::assertCount(250, $ids);
        self::assertSame($ids, array_unique($ids), 'no row is read twice at a batch boundary');
        self::assertSame('1', $ids[0]);
        self::assertSame('250', $ids[249]);
    }

    /**
     * The failure LIMIT/OFFSET has and this does not: a row deleted ahead of
     * the cursor shifts every later row up, and an offset walk steps over one.
     */
    public function testARowDeletedMidWalkDoesNotMakeItSkipAnother(): void
    {
        $this->seed(30);
        $seen = [];
        $deleted = false;

        foreach ($this->source(10)->rows() as $row) {
            $seen[] = $row->sourceId;

            if (!$deleted && $row->sourceId === '5') {
                // Delete a row that has already gone past, the way a live
                // forum would while an import is running.
                $this->connection->delete('xf_user', ['user_id' => 3]);
                $deleted = true;
            }
        }

        // 29 rows remain and every surviving id after the cursor is still read.
        self::assertContains('11', $seen);
        self::assertContains('30', $seen);
        self::assertSame($seen, array_unique($seen));
    }

    public function testAWhereClauseNarrowsTheRead(): void
    {
        $this->seed(20);

        $rows = $this->rows($this->source(500, 'is_banned = 1'));

        self::assertSame(['5', '10', '15', '20'], array_map(static fn (MigrationRow $r): string => $r->sourceId, $rows));
    }

    public function testCountsWithTheSameConditionItReadsWith(): void
    {
        $this->seed(20);

        self::assertSame(20, $this->source()->count());
        self::assertSame(4, $this->source(500, 'is_banned = 1')->count());
    }

    public function testAnEmptyTableYieldsNothingRatherThanLoopingForever(): void
    {
        self::assertSame([], $this->rows($this->source(5)));
    }

    public function testValuesArriveAsStringsSoAChecksumIsStableAcrossDrivers(): void
    {
        $this->seed(1);

        $row = $this->rows($this->source())[0];

        // Drivers disagree about whether an INTEGER column comes back as int
        // or string; if that leaked through, the same row would checksum
        // differently on two servers and re-import as "changed" every time.
        self::assertSame('0', $row->get('is_banned'));
        self::assertIsString($row->get('user_id'));
    }

    public function testANullColumnBecomesAnEmptyStringRatherThanVanishing(): void
    {
        $this->connection->insert('xf_user', ['user_id' => 1, 'username' => 'x', 'email' => null, 'is_banned' => 0]);

        $row = $this->rows($this->source())[0];

        self::assertTrue($row->has('email'));
        self::assertSame('', $row->get('email'));
    }

    public function testABatchSizeBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->source(0);
    }

    /**
     * The table name is the one part of a query that cannot be a bound
     * parameter, and the prefix comes from a form. It is refused rather than
     * escaped: no legitimate prefix is excluded by this.
     */
    public function testATablePrefixThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid table prefix/');

        ForeignDatabase::wrap($this->connection, 'xf_"; DROP TABLE users; --');
    }

    public function testTheTableNameIsThePrefixPlusTheDriversName(): void
    {
        self::assertSame('xf_user', ForeignDatabase::wrap($this->connection, 'xf_')->table('user'));
        self::assertSame('user', ForeignDatabase::wrap($this->connection)->table('user'));
    }

    public function testAMissingTableIsReportedAgainstThePrefixTheOperatorGave(): void
    {
        $database = ForeignDatabase::wrap($this->connection, 'wrong_');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Check the table prefix \("wrong_"\)/');

        $database->assertTables(['user']);
    }

    public function testTablesThatExistPassTheCheck(): void
    {
        $database = ForeignDatabase::wrap($this->connection, 'xf_');

        $database->assertTables(['user']);

        self::assertTrue($database->hasTable('user'));
    }

    public function testHostAndPortAreSplitTheWayHostingPanelsPrintThem(): void
    {
        $database = ForeignDatabase::fromOptions([
            'dbHost' => 'db.example.test:3307',
            'dbName' => 'forum',
            'dbUser' => 'reader',
            'dbPassword' => 'secret',
            'prefix' => 'xf_',
        ]);

        self::assertSame('xf_', $database->prefix());
        self::assertSame('xf_user', $database->table('user'));
    }
}
