<?php

declare(strict_types=1);

namespace App\Core\Migrate\Map;

/**
 * Remembers which source row became which destination record.
 *
 * This is the single thing that makes an import safe to run twice, and the
 * single thing that makes rollback exact. It is written by the runner AFTER
 * the destination write returned — the same discipline the update-hook ledger
 * uses. A crash between write and record means the row is imported again on
 * the next run and matched by its source id, which is recoverable. Recording
 * first would mean the row is marked done when it never landed, which is not.
 */
interface MigrationMapInterface
{
    public function find(string $migrationId, string $sourceId): ?MigrationMapRecord;

    public function record(MigrationMapRecord $record): void;

    public function forget(string $migrationId, string $sourceId): void;

    /**
     * Everything this migration has written, newest first.
     *
     * Rollback walks it in that order so records created later — which may
     * reference earlier ones — come out first.
     *
     * @return list<MigrationMapRecord>
     */
    public function entries(string $migrationId): array;

    public function countFor(string $migrationId): int;

    /**
     * How many rows each of these migrations has imported, in ONE query.
     *
     * A screen listing every migration needs this number for all of them, and
     * asking one at a time is a real N+1 — the kind Law 6.1 exists to catch,
     * and did: opening the import page with eighteen migrations registered
     * issued eighteen reads of the map and tripped the guard at eleven.
     *
     * @param list<string> $migrationIds
     *
     * @return array<string, int> migration id => count, including zeroes
     */
    public function countsFor(array $migrationIds): array;
}
