<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Migrate\Map\ArrayMigrationMap;
use App\Core\Migrate\Map\MigrationMapRecord;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationRunner;
use App\Tests\Unit\Core\Migrate\Support\ArraySource;
use App\Tests\Unit\Core\Migrate\Support\RecordingDestination;
use App\Tests\Unit\Core\Migrate\Support\TestMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(ArrayMigrationMap::class)]
#[CoversClass(MigrationRow::class)]
final class MigrationRunnerTest extends TestCase
{
    public function testFirstRunCreatesEveryRowAndRecordsTheMap(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $migration = new TestMigration('test.rows', $this->source(['a', 'b', 'c']), $destination);

        $report = (new MigrationRunner($map))->run($migration, false);

        self::assertSame(3, $report->created());
        self::assertSame(0, $report->updated());
        self::assertSame(3, $report->processed());
        self::assertFalse($report->hasFailures());
        self::assertCount(3, $destination->writes);
        self::assertSame(3, $map->countFor('test.rows'));
        self::assertSame('dest-a', $map->find('test.rows', 'a')?->destinationId);
    }

    public function testSecondRunOfIdenticalRowsChangesNothing(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);

        $runner->run(new TestMigration('test.rows', $this->source(['a', 'b']), $destination), false);
        $second = $runner->run(new TestMigration('test.rows', $this->source(['a', 'b']), $destination), false);

        self::assertSame(2, $second->unchanged());
        self::assertSame(0, $second->created());
        self::assertSame(0, $second->updated());
        // The destination was not touched a second time: re-running an import
        // must not rewrite rows that did not change.
        self::assertCount(2, $destination->writes);
    }

    public function testAChangedSourceRowUpdatesInPlaceInsteadOfDuplicating(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);

        $runner->run(new TestMigration('test.rows', new ArraySource([
            new MigrationRow('a', ['title' => 'before']),
        ]), $destination), false);

        $report = $runner->run(new TestMigration('test.rows', new ArraySource([
            new MigrationRow('a', ['title' => 'after']),
        ]), $destination), false);

        self::assertSame(1, $report->updated());
        self::assertSame(0, $report->created());
        self::assertCount(2, $destination->writes);
        // The second write was handed the id recorded by the first — that is
        // what stops a re-import turning into duplicated content.
        self::assertSame('dest-a', $destination->writes[1]['existingId']);
        self::assertSame(1, $map->countFor('test.rows'));
    }

    public function testChecksumIgnoresKeyOrderSoAReorderedSourceIsNotSeenAsChanged(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);

        $runner->run(new TestMigration('test.rows', new ArraySource([
            new MigrationRow('a', ['title' => 'x', 'body' => 'y']),
        ]), $destination), false);

        $report = $runner->run(new TestMigration('test.rows', new ArraySource([
            new MigrationRow('a', ['body' => 'y', 'title' => 'x']),
        ]), $destination), false);

        self::assertSame(1, $report->unchanged());
        self::assertCount(1, $destination->writes);
    }

    public function testARowThatThrowsIsReportedAndTheRunContinues(): void
    {
        $destination = new RecordingDestination(failFor: ['b']);
        $map = new ArrayMigrationMap();
        $migration = new TestMigration('test.rows', $this->source(['a', 'b', 'c']), $destination);

        $report = (new MigrationRunner($map))->run($migration, false);

        self::assertSame(2, $report->created());
        self::assertSame(1, $report->failed());
        self::assertSame('b', $report->failures()[0]['sourceId']);
        self::assertStringContainsString('row b is broken', $report->failures()[0]['message']);
        // The failed row left no map entry, so the next run retries it.
        self::assertNull($map->find('test.rows', 'b'));
        self::assertSame(2, $map->countFor('test.rows'));
    }

    public function testATransformReturningNullSkipsTheRowWithoutCountingItAsAFailure(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $migration = new TestMigration(
            'test.rows',
            $this->source(['a', 'b', 'c']),
            $destination,
            transform: static fn (MigrationRow $row): ?MigrationRow => $row->sourceId === 'b' ? null : $row,
        );

        $report = (new MigrationRunner($map))->run($migration, false);

        self::assertSame(1, $report->skipped());
        self::assertSame(0, $report->failed());
        self::assertSame(2, $report->created());
        self::assertSame(3, $report->processed());
    }

    public function testATransformThatThrowsIsARowFailureNotARunFailure(): void
    {
        $destination = new RecordingDestination();
        $migration = new TestMigration(
            'test.rows',
            $this->source(['a', 'b']),
            $destination,
            transform: static function (MigrationRow $row): MigrationRow {
                if ($row->sourceId === 'a') {
                    throw new \RuntimeException('bad source data');
                }

                return $row;
            },
        );

        $report = (new MigrationRunner(new ArrayMigrationMap()))->run($migration, false);

        self::assertSame(1, $report->failed());
        self::assertSame(1, $report->created());
        self::assertSame('bad source data', $report->failures()[0]['message']);
    }

    public function testDryRunWritesNothingButReportsTheSameCounters(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $migration = new TestMigration('test.rows', $this->source(['a', 'b', 'c']), $destination);

        $report = (new MigrationRunner($map))->run($migration, true);

        self::assertTrue($report->dryRun);
        self::assertSame(3, $report->created());
        self::assertSame([], $destination->writes, 'A dry run must not reach the destination.');
        self::assertSame(0, $map->countFor('test.rows'), 'A dry run must not write the map.');
    }

    public function testDryRunSeesWhatIsAlreadyImportedInsteadOfCallingEverythingNew(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);

        $runner->run(new TestMigration('test.rows', $this->source(['a', 'b']), $destination), false);

        $report = $runner->run(new TestMigration('test.rows', new ArraySource([
            new MigrationRow('a', ['title' => 'a']),
            new MigrationRow('b', ['title' => 'changed']),
            new MigrationRow('c', ['title' => 'c']),
        ]), $destination), true);

        self::assertSame(1, $report->unchanged(), 'a is already imported and identical');
        self::assertSame(1, $report->updated(), 'b changed at the source');
        self::assertSame(1, $report->created(), 'c is new');
    }

    public function testDryRunTreatsARepeatedSourceKeyAsAnUpdateJustAsARealRunWould(): void
    {
        $map = new ArrayMigrationMap();
        $migration = new TestMigration('test.rows', new ArraySource([
            new MigrationRow('a', ['title' => 'first']),
            new MigrationRow('a', ['title' => 'second']),
        ]), new RecordingDestination());

        $report = (new MigrationRunner($map))->run($migration, true);

        self::assertSame(1, $report->created());
        self::assertSame(1, $report->updated(), 'The second occurrence of the same key is an update, not a second create.');
    }

    public function testLimitStopsReadingTheSource(): void
    {
        $source = new ArraySource([
            new MigrationRow('a', ['n' => 1]),
            new MigrationRow('b', ['n' => 2]),
            new MigrationRow('c', ['n' => 3]),
        ]);
        $migration = new TestMigration('test.rows', $source, new RecordingDestination());

        $report = (new MigrationRunner(new ArrayMigrationMap()))->run($migration, false, 2);

        self::assertSame(2, $report->created());
        self::assertTrue($report->limitReached());
        // Three rows were pulled: two imported, the third is what told the
        // runner the source had more. The source is not drained.
        self::assertSame(3, $source->rowsPulled);
    }

    public function testASecondLimitedRunContinuesPastRowsAlreadyImported(): void
    {
        $source = new ArraySource([
            new MigrationRow('a', ['n' => 1]),
            new MigrationRow('b', ['n' => 2]),
            new MigrationRow('c', ['n' => 3]),
            new MigrationRow('d', ['n' => 4]),
        ]);
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);
        $migration = new TestMigration('test.rows', $source, new RecordingDestination());

        $first = $runner->run($migration, false, 2);
        self::assertSame(2, $first->created());
        self::assertTrue($first->limitReached());

        $second = $runner->run($migration, false, 2);
        self::assertSame(2, $second->unchanged(), 'a and b are already imported');
        self::assertSame(2, $second->created(), 'c and d are the next batch, not a repeat of a and b');
        self::assertSame(4, $map->countFor('test.rows'));
    }

    public function testDryRunOfADependentMigrationSeesParentsFromEarlierStepsInTheSameBatch(): void
    {
        $map = new ArrayMigrationMap();
        $lookup = new MigrationLookup($map);
        $runner = new MigrationRunner($map, null, null, $lookup);

        $parents = new TestMigration('test.parents', $this->source(['p1']), new RecordingDestination());
        $children = new TestMigration(
            'test.children',
            new ArraySource([new MigrationRow('c1', ['parent' => 'p1'])]),
            new RecordingDestination(),
            ['test.parents'],
            static function (MigrationRow $row) use ($lookup): ?MigrationRow {
                $parent = $lookup->find('test.parents', $row->getString('parent'));

                return $parent === null ? null : $row->withData(['parentId' => $parent]);
            },
        );

        $parentReport = $runner->run($parents, true);
        $childReport = $runner->run($children, true);

        self::assertSame(1, $parentReport->created());
        self::assertSame(1, $childReport->created(), 'the child must resolve the parent previewed in this dry run');
        self::assertSame(0, $childReport->skipped());
        self::assertSame(0, $map->countFor('test.parents'), 'still a dry run: the real map is untouched');
    }

    public function testAZeroOrNegativeLimitIsRefusedRatherThanImportingEverything(): void
    {
        $migration = new TestMigration('test.rows', $this->source(['a']), new RecordingDestination());

        $this->expectException(\InvalidArgumentException::class);

        (new MigrationRunner(new ArrayMigrationMap()))->run($migration, false, 0);
    }

    public function testRollbackDeletesNewestFirstAndClearsTheMap(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);
        $migration = new TestMigration('test.rows', $this->source(['a', 'b', 'c']), $destination);

        $runner->run($migration, false);
        $report = $runner->rollback($migration, false);

        self::assertSame(3, $report->updated());
        self::assertSame(['dest-c', 'dest-b', 'dest-a'], $destination->deletes);
        self::assertSame(0, $map->countFor('test.rows'));
    }

    public function testRollbackDryRunDeletesNothing(): void
    {
        $destination = new RecordingDestination();
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);
        $migration = new TestMigration('test.rows', $this->source(['a', 'b']), $destination);

        $runner->run($migration, false);
        $report = $runner->rollback($migration, true);

        self::assertSame(2, $report->updated());
        self::assertSame([], $destination->deletes);
        self::assertSame(2, $map->countFor('test.rows'));
    }

    public function testRollbackKeepsGoingWhenOneDeleteFailsAndLeavesThatRowMapped(): void
    {
        $destination = new RecordingDestination(deleteFailsFor: ['dest-b']);
        $map = new ArrayMigrationMap();
        $runner = new MigrationRunner($map);
        $migration = new TestMigration('test.rows', $this->source(['a', 'b', 'c']), $destination);

        $runner->run($migration, false);
        $report = $runner->rollback($migration, false);

        self::assertSame(1, $report->failed());
        self::assertSame(2, $report->updated());
        // b is still mapped, so a second rollback can retry exactly that row.
        self::assertNotNull($map->find('test.rows', 'b'));
        self::assertSame(1, $map->countFor('test.rows'));
    }

    public function testImportedCountReadsThePersistentMap(): void
    {
        $map = new ArrayMigrationMap([
            new MigrationMapRecord('test.rows', 'a', 'sum', 'test', 'dest-a'),
        ]);
        $migration = new TestMigration('test.rows', $this->source([]), new RecordingDestination());

        self::assertSame(1, (new MigrationRunner($map))->importedCount($migration));
    }

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
}
