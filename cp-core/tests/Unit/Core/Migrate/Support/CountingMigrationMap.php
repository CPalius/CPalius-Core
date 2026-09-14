<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate\Support;

use App\Core\Migrate\Map\ArrayMigrationMap;
use App\Core\Migrate\Map\MigrationMapInterface;
use App\Core\Migrate\Map\MigrationMapRecord;

/**
 * A real map that also records how it was asked.
 *
 * Delegates to ArrayMigrationMap rather than extending it — core classes are
 * final on purpose — so the behaviour under test is the real implementation
 * and only the bookkeeping is added.
 */
final class CountingMigrationMap implements MigrationMapInterface
{
    public int $countsForCalls = 0;

    public int $countForCalls = 0;

    /** @var list<string> ids passed to the most recent batch call */
    public array $lastBatch = [];

    private readonly ArrayMigrationMap $inner;

    /**
     * @param list<MigrationMapRecord> $seed
     */
    public function __construct(array $seed = [])
    {
        $this->inner = new ArrayMigrationMap($seed);
    }

    public function find(string $migrationId, string $sourceId): ?MigrationMapRecord
    {
        return $this->inner->find($migrationId, $sourceId);
    }

    public function record(MigrationMapRecord $record): void
    {
        $this->inner->record($record);
    }

    public function forget(string $migrationId, string $sourceId): void
    {
        $this->inner->forget($migrationId, $sourceId);
    }

    public function entries(string $migrationId): array
    {
        return $this->inner->entries($migrationId);
    }

    public function countFor(string $migrationId): int
    {
        ++$this->countForCalls;

        return $this->inner->countFor($migrationId);
    }

    public function countsFor(array $migrationIds): array
    {
        ++$this->countsForCalls;
        $this->lastBatch = $migrationIds;

        return $this->inner->countsFor($migrationIds);
    }
}
