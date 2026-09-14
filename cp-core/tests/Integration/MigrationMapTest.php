<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\Map\MigrationMapRecord;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The persistent map, against a real database.
 *
 * The batch count exists because the import screen asked for it once per
 * migration and tripped Law 6.1 at eleven reads. A unit test can hold that the
 * runner makes one call; only this can hold that the query behind that call is
 * valid DQL which returns the right numbers.
 */
#[CoversClass(DoctrineMigrationMap::class)]
final class MigrationMapTest extends IntegrationTestCase
{
    private function map(): DoctrineMigrationMap
    {
        return new DoctrineMigrationMap($this->em());
    }

    private function seed(string $migrationId, int $rows): void
    {
        $map = $this->map();

        for ($i = 1; $i <= $rows; ++$i) {
            $map->record(new MigrationMapRecord($migrationId, (string) $i, 'sum'.$i, 'node', 'dest-'.$i));
        }
    }

    public function testCountsForReturnsOneNumberPerMigrationIncludingZeroes(): void
    {
        $this->seed('wordpress.posts', 3);
        $this->seed('xenforo.users', 1);

        $counts = $this->map()->countsFor(['wordpress.posts', 'xenforo.users', 'mybb.posts']);

        self::assertSame(
            ['wordpress.posts' => 3, 'xenforo.users' => 1, 'mybb.posts' => 0],
            $counts,
        );
    }

    public function testCountsForAgreesWithCountingOneAtATime(): void
    {
        $this->seed('a.one', 4);
        $this->seed('b.two', 2);

        $map = $this->map();
        $batch = $map->countsFor(['a.one', 'b.two']);

        self::assertSame($map->countFor('a.one'), $batch['a.one']);
        self::assertSame($map->countFor('b.two'), $batch['b.two']);
    }

    public function testCountsForIsEmptyWhenAskedForNothing(): void
    {
        self::assertSame([], $this->map()->countsFor([]));
    }

    /**
     * countFor() used to hydrate every mapped row into an entity to measure how
     * many there were, so a screen opened after a large import loaded the whole
     * map to print one number. It counts in the database now.
     */
    public function testCountingDoesNotHydrateTheRowsItCounts(): void
    {
        $this->seed('big.one', 12);

        $em = $this->em();
        $em->clear();

        self::assertSame(12, $this->map()->countFor('big.one'));

        // Nothing was loaded into the identity map by counting.
        self::assertFalse(
            $em->getUnitOfWork()->getIdentityMap() !== [] && isset($em->getUnitOfWork()->getIdentityMap()[\App\Core\Migrate\Entity\MigrationMapEntry::class]),
            'counting must not load the rows',
        );
    }

    public function testARecordedRowIsFoundBackWithItsChecksum(): void
    {
        $map = $this->map();
        $map->record(new MigrationMapRecord('a.one', '42', 'abc', 'node', '7'));

        $found = $map->find('a.one', '42');

        self::assertNotNull($found);
        self::assertSame('abc', $found->checksum);
        self::assertSame('7', $found->destinationId);
    }
}
