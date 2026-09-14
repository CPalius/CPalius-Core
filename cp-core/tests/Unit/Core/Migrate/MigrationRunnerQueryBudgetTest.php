<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Database\MaxQueriesExceededException;
use App\Core\Database\QueryCounter;
use App\Core\Migrate\Map\ArrayMigrationMap;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationRunner;
use App\Tests\Unit\Core\Migrate\Support\ArraySource;
use App\Tests\Unit\Core\Migrate\Support\TestMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Law 6.1's budget, applied per row instead of per request.
 *
 * THE BUG THIS EXISTS FOR
 * An operator importing from the panel got "N+1 query detected: table
 * cp_migration_map was queried 11 times in this request (limit: 10)". The guard
 * assumes one request renders one page, so eleven reads of a table means a loop
 * over lazy-loaded relations. A batch import breaks that assumption honestly:
 * it reads the map and the destination table once per row because that is the
 * work. Any import past ten rows was therefore killed.
 *
 * WHY THIS IS A UNIT TEST AND NOT AN INTEGRATION ONE
 * QueryCounterMiddleware::wrap() returns the driver unwrapped when PHP_SAPI is
 * "cli", so the tripwire is inert under PHPUnit and an integration test cannot
 * reproduce the failure at all — which is also why no existing test caught it.
 * Driving the counter directly is the only honest way to hold this behaviour,
 * and it holds the thing that actually matters: the runner hands each row a
 * fresh budget, and a loop inside one row still trips.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(QueryCounter::class)]
final class MigrationRunnerQueryBudgetTest extends TestCase
{
    /**
     * @param list<string> $ids
     */
    private function source(array $ids): ArraySource
    {
        return new ArraySource(array_map(
            static fn (string $id): MigrationRow => new MigrationRow($id, ['title' => $id]),
            $ids,
        ));
    }

    /**
     * @return list<string>
     */
    private function ids(int $count): array
    {
        return array_map(static fn (int $i): string => (string) $i, range(1, $count));
    }

    /**
     * A destination that reads its table once per row, which is what every real
     * one does: look for an existing record, then write.
     */
    private function destination(QueryCounter $counter, int $readsPerRow = 1): MigrationDestinationInterface
    {
        return new class($counter, $readsPerRow) implements MigrationDestinationInterface {
            public function __construct(
                private readonly QueryCounter $counter,
                private readonly int $readsPerRow,
            ) {
            }

            public function describe(): string
            {
                return 'counting destination';
            }

            public function entityType(): string
            {
                return 'test';
            }

            public function write(MigrationRow $row, ?string $existingId): string
            {
                for ($i = 0; $i < $this->readsPerRow; ++$i) {
                    $this->counter->increment('nodes');
                }

                return 'dest-'.$row->sourceId;
            }

            public function delete(string $destinationId): bool
            {
                $this->counter->increment('nodes');

                return true;
            }
        };
    }

    public function testAnImportOfMoreRowsThanTheBudgetCompletes(): void
    {
        $counter = new QueryCounter(10);
        $destination = $this->destination($counter);
        $migration = new TestMigration('test.big', $this->source($this->ids(25)), $destination);

        $report = (new MigrationRunner(new ArrayMigrationMap(), null, $counter))->run($migration, false);

        self::assertSame(25, $report->created());
        self::assertFalse($report->hasFailures(), 'twenty-five rows is not an N+1');
    }

    /**
     * The negative control: without the per-row reset this is exactly the
     * failure the operator saw, and it arrives as a row failure because the
     * runner isolates each row.
     */
    public function testWithoutAFreshBudgetTheSameImportFailsPastTheLimit(): void
    {
        $counter = new QueryCounter(10);
        $destination = $this->destination($counter);
        $migration = new TestMigration('test.big', $this->source($this->ids(25)), $destination);

        // No counter handed to the runner: the budget is never renewed.
        $report = (new MigrationRunner(new ArrayMigrationMap()))->run($migration, false);

        self::assertSame(10, $report->created(), 'the first ten rows land');
        self::assertSame(15, $report->failed(), 'and everything past the limit is refused');
        self::assertStringContainsString('N+1 query detected', $report->failures()[0]['message']);
    }

    /**
     * The guard keeps doing its job where it still applies: reading one table
     * eleven times while handling a SINGLE row is a loop, and still trips.
     */
    public function testALoopInsideOneRowStillTrips(): void
    {
        $counter = new QueryCounter(10);
        $destination = $this->destination($counter, readsPerRow: 11);
        $migration = new TestMigration('test.loopy', $this->source(['1', '2']), $destination);

        $report = (new MigrationRunner(new ArrayMigrationMap(), null, $counter))->run($migration, false);

        self::assertSame(0, $report->created());
        self::assertSame(2, $report->failed());
        self::assertStringContainsString('N+1 query detected', $report->failures()[0]['message']);
    }

    public function testRollbackGetsAFreshBudgetPerRowToo(): void
    {
        $counter = new QueryCounter(10);
        $destination = $this->destination($counter);
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map, null, $counter);
        $migration = new TestMigration('test.big', $this->source($this->ids(25)), $destination);

        $runner->run($migration, false);
        $report = $runner->rollback($migration, false);

        self::assertSame(25, $report->updated());
        self::assertFalse($report->hasFailures());
    }

    public function testTheCounterStillThrowsOnItsOwnTerms(): void
    {
        $counter = new QueryCounter(2);

        $counter->increment('nodes');
        $counter->increment('nodes');

        $this->expectException(MaxQueriesExceededException::class);

        $counter->increment('nodes');
    }
}
