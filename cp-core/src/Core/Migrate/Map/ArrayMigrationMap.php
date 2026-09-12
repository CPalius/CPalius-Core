<?php

declare(strict_types=1);

namespace App\Core\Migrate\Map;

/**
 * In-memory map.
 *
 * Ships in core rather than in the test folder because it has a production job:
 * a dry run must not touch the real map, but it still has to behave like one
 * within the run — otherwise a source file containing the same key twice would
 * be reported as two creations, and the operator would be told the import does
 * something it will not do. The runner therefore layers one of these over the
 * real map while dry-running.
 *
 * It is also what the runner's tests use, so those tests exercise the real
 * contract instead of a mock's idea of it.
 */
final class ArrayMigrationMap implements MigrationMapInterface
{
    /** @var array<string, array<string, MigrationMapRecord>> migration id => source id => record */
    private array $records = [];

    /**
     * @param list<MigrationMapRecord> $seed rows the map already holds, e.g. copied from the persistent map for a dry run
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $record) {
            $this->record($record);
        }
    }

    public function find(string $migrationId, string $sourceId): ?MigrationMapRecord
    {
        return $this->records[$migrationId][$sourceId] ?? null;
    }

    public function record(MigrationMapRecord $record): void
    {
        $this->records[$record->migrationId][$record->sourceId] = $record;
    }

    public function forget(string $migrationId, string $sourceId): void
    {
        unset($this->records[$migrationId][$sourceId]);
    }

    public function entries(string $migrationId): array
    {
        return array_reverse(array_values($this->records[$migrationId] ?? []));
    }

    public function countFor(string $migrationId): int
    {
        return \count($this->records[$migrationId] ?? []);
    }
}
