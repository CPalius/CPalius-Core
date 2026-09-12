<?php

declare(strict_types=1);

namespace App\Core\Migrate;

use App\Core\Migrate\Map\ArrayMigrationMap;
use App\Core\Migrate\Map\MigrationMapInterface;
use App\Core\Migrate\Map\MigrationMapRecord;
use Psr\Log\LoggerInterface;

/**
 * Runs an import, or tells you what one would do.
 *
 * FOUR PROPERTIES, AND WHY EACH IS HERE
 *
 * 1. Dry run first. Every other CPalius operator tool opens with one
 *    (cp:update, cp:doctor, the AACP updates screen), and an import is the
 *    least reversible thing in the product. Drupal's Migrate API — the leader
 *    on this scorecard row — has no dry run at all: you find out what it does
 *    by letting it do it. Here --dry-run reads the source, runs every
 *    transform, consults the map and reports the exact same counters, having
 *    written nothing.
 *
 * 2. Per-row isolation. One malformed row out of fifty thousand must not end
 *    the run; that is Law 2.2 applied to data rather than to modules. A row
 *    that throws is recorded with its source id and the run continues. The
 *    alternative — abort on first error — means the operator fixes one row,
 *    re-runs, and discovers the next one an hour later.
 *
 * 3. The map is written AFTER the destination write returns. A crash in
 *    between means the row is imported again next run and matched by source
 *    id; recording first would mark a row done that never landed. Same
 *    discipline as UpdateHookLedger, for the same reason.
 *
 * 4. Re-runnable by construction. A row already in the map with an identical
 *    checksum is left alone, a changed one is updated in place, a new one is
 *    created. So the normal way to use this is to run it repeatedly while
 *    tuning the transform, which is the only way anyone actually migrates a
 *    real site.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly MigrationMapInterface $map,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param int|null $limit stop after this many source rows, for trying an import out on a handful
     */
    public function run(MigrationInterface $migration, bool $dryRun = false, ?int $limit = null): MigrationReport
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException(sprintf('A row limit must be at least 1, got %d.', $limit));
        }

        $report = new MigrationReport($migration->id(), $dryRun);
        $map = $this->mapFor($migration, $dryRun);
        $destination = $migration->destination();
        $seen = 0;

        foreach ($migration->source()->rows() as $row) {
            if ($limit !== null && $seen >= $limit) {
                $report->markLimitReached();
                break;
            }
            ++$seen;

            try {
                $this->importRow($migration, $destination, $map, $row, $dryRun, $report);
            } catch (\Throwable $e) {
                // Law 2.2 for data: this row is lost, the run is not.
                $report->recordFailure($row->sourceId, $e->getMessage());
                $this->logger?->error('Migration row failed', [
                    'migration' => $migration->id(),
                    'sourceId' => $row->sourceId,
                    'exception' => $e,
                ]);
            }
        }

        return $report;
    }

    /**
     * Removes everything this migration wrote, newest first.
     *
     * Exact rather than best-effort: it deletes what the map says landed, and
     * forgets each row only after the destination confirmed. A record already
     * deleted by hand counts as rolled back — refusing to finish because
     * something is already in the desired state helps nobody.
     */
    public function rollback(MigrationInterface $migration, bool $dryRun = false): MigrationReport
    {
        $report = new MigrationReport($migration->id(), $dryRun);
        $destination = $migration->destination();

        foreach ($this->map->entries($migration->id()) as $record) {
            try {
                if ($dryRun) {
                    $report->recordUpdated();
                    continue;
                }

                $destination->delete($record->destinationId);
                $this->map->forget($record->migrationId, $record->sourceId);
                $report->recordUpdated();
            } catch (\Throwable $e) {
                $report->recordFailure($record->sourceId, $e->getMessage());
                $this->logger?->error('Migration rollback failed for a row', [
                    'migration' => $migration->id(),
                    'sourceId' => $record->sourceId,
                    'exception' => $e,
                ]);
            }
        }

        return $report;
    }

    /**
     * How much of this migration is already recorded as imported.
     */
    public function importedCount(MigrationInterface $migration): int
    {
        return $this->map->countFor($migration->id());
    }

    private function importRow(
        MigrationInterface $migration,
        MigrationDestinationInterface $destination,
        MigrationMapInterface $map,
        MigrationRow $row,
        bool $dryRun,
        MigrationReport $report,
    ): void {
        $transformed = $migration->transform($row);

        if ($transformed === null) {
            $report->recordSkipped();

            return;
        }

        $checksum = $transformed->checksum();
        $existing = $map->find($migration->id(), $row->sourceId);

        if ($existing !== null && $existing->checksum === $checksum) {
            $report->recordUnchanged();

            return;
        }

        if ($dryRun) {
            // Record into the throwaway map so a source that repeats a key
            // reports the second occurrence as an update, exactly as the real
            // run would. Destination id is a placeholder: nothing reads it.
            $map->record(new MigrationMapRecord(
                $migration->id(),
                $row->sourceId,
                $checksum,
                $destination->entityType(),
                $existing->destinationId ?? '',
            ));

            $existing === null ? $report->recordCreated() : $report->recordUpdated();

            return;
        }

        $destinationId = $destination->write($transformed, $existing?->destinationId);

        // Only now, with the write returned, is the row actually done.
        $map->record(new MigrationMapRecord(
            $migration->id(),
            $row->sourceId,
            $checksum,
            $destination->entityType(),
            $destinationId,
        ));

        $existing === null ? $report->recordCreated() : $report->recordUpdated();
    }

    /**
     * A dry run gets a scratch map seeded from the real one: it must see what
     * has already been imported (otherwise everything reads as "created") but
     * must not write to it.
     */
    private function mapFor(MigrationInterface $migration, bool $dryRun): MigrationMapInterface
    {
        if (!$dryRun) {
            return $this->map;
        }

        return new ArrayMigrationMap($this->map->entries($migration->id()));
    }
}
