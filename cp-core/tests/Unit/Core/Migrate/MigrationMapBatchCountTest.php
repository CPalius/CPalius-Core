<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Migrate\Map\ArrayMigrationMap;
use App\Core\Migrate\Map\MigrationMapRecord;
use App\Core\Migrate\MigrationRunner;
use App\Tests\Unit\Core\Migrate\Support\ArraySource;
use App\Tests\Unit\Core\Migrate\Support\CountingMigrationMap;
use App\Tests\Unit\Core\Migrate\Support\RecordingDestination;
use App\Tests\Unit\Core\Migrate\Support\TestMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Counting what several migrations have imported must cost one query.
 *
 * THE BUG THIS EXISTS FOR
 * The import screen showed "rows already imported" per source system, and got
 * that number by asking the map once per migration. With eighteen migrations
 * registered that is eighteen reads of cp_migration_map to render one page —
 * a real N+1, of exactly the kind Law 6.1 is meant to catch, and it caught it:
 * the page died with "table cp_migration_map was queried 11 times".
 *
 * Note what that means: the guard was right, and the first attempt at a fix was
 * aimed at the wrong place. Batching the count is the fix. The per-row budget
 * held by MigrationRunnerQueryBudgetTest is a separate, also-real problem in
 * the import loop itself.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(ArrayMigrationMap::class)]
final class MigrationMapBatchCountTest extends TestCase
{
    /**
     * @param list<string> $ids
     *
     * @return list<TestMigration>
     */
    private function migrations(array $ids): array
    {
        return array_map(
            static fn (string $id): TestMigration => new TestMigration($id, new ArraySource([]), new RecordingDestination()),
            $ids,
        );
    }

    public function testCountingManyMigrationsTakesOneCallToTheMap(): void
    {
        $map = new CountingMigrationMap();

        (new MigrationRunner($map))->importedCounts(
            $this->migrations(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l']),
        );

        // One batch call, and not one per migration — which is the whole point.
        self::assertSame(1, $map->countsForCalls);
        self::assertSame(0, $map->countForCalls);
    }

    public function testTheBatchReportsZeroForMigrationsThatHaveImportedNothing(): void
    {
        $map = new CountingMigrationMap([
            new MigrationMapRecord('a', '1', 'sum', 'test', 'x'),
            new MigrationMapRecord('a', '2', 'sum', 'test', 'y'),
            new MigrationMapRecord('b', '1', 'sum', 'test', 'z'),
        ]);

        $counts = (new MigrationRunner($map))->importedCounts($this->migrations(['a', 'b', 'c']));

        // A migration with nothing imported must appear as 0 rather than be
        // missing, or the screen renders a blank where a number belongs.
        self::assertSame(['a' => 2, 'b' => 1, 'c' => 0], $counts);
    }

    public function testAskingForNothingIsNotAQuery(): void
    {
        self::assertSame([], (new MigrationRunner(new ArrayMigrationMap()))->importedCounts([]));
    }

    public function testTheSameMigrationTwiceIsAskedForOnce(): void
    {
        $map = new CountingMigrationMap();

        (new MigrationRunner($map))->importedCounts($this->migrations(['a', 'b', 'a']));

        self::assertSame(['a', 'b'], $map->lastBatch);
    }

}
