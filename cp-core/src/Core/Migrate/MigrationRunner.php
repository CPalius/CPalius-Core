<?php

declare(strict_types=1);

namespace App\Core\Migrate;

use App\Core\Database\QueryCounter;
use App\Core\Migrate\Map\ArrayMigrationMap;
use App\Core\Migrate\Map\MigrationMapInterface;
use App\Core\Migrate\Map\MigrationMapRecord;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
    /**
     * Shared across every dry-run() in one request so later migrations can
     * resolve parents that this request already previewed.
     */
    private ?ArrayMigrationMap $dryRunLedger = null;

    public function __construct(
        private readonly MigrationMapInterface $map,
        private readonly ?LoggerInterface $logger = null,
        /**
         * Present in dev and test, where Law 6.1's N+1 tripwire runs. See
         * newRow() for why a batch has to hand it a fresh budget per row.
         */
        private readonly ?QueryCounter $queryCounter = null,
        private readonly ?MigrationLookup $lookup = null,
        private readonly ?ManagerRegistry $doctrine = null,
    ) {
    }

    /**
     * @param int|null $limit stop after this many rows that still need work
     *                        (created, updated, skipped or failed). Rows
     *                        already imported and unchanged do not count, so
     *                        a second web run with limit 200 continues rather
     *                        than re-reading the same first 200 forever.
     */
    public function run(MigrationInterface $migration, bool $dryRun = false, ?int $limit = null): MigrationReport
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException(sprintf('A row limit must be at least 1, got %d.', $limit));
        }

        $report = new MigrationReport($migration->id(), $dryRun);
        $map = $this->mapFor($migration, $dryRun);
        $destination = $migration->destination();
        $worked = 0;

        foreach ($migration->source()->rows() as $row) {
            if ($limit !== null && $worked >= $limit) {
                $report->markLimitReached();
                break;
            }
            $this->newRow();

            $unchangedBefore = $report->unchanged();

            try {
                $this->importRow($migration, $destination, $map, $row, $dryRun, $report);
            } catch (\Throwable $e) {
                // Law 2.2 for data: this row is lost, the run is not.
                $this->recoverManager();
                $report->recordFailure($row->sourceId, $e->getMessage());
                $this->logger?->error('Migration row failed', [
                    'migration' => $migration->id(),
                    'sourceId' => $row->sourceId,
                    'exception' => $e,
                ]);
            }

            if ($report->unchanged() === $unchangedBefore) {
                ++$worked;
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
            $this->newRow();

            try {
                if ($dryRun) {
                    $report->recordUpdated();
                    continue;
                }

                $destination->delete($record->destinationId);
                $this->map->forget($record->migrationId, $record->sourceId);
                $report->recordUpdated();
            } catch (\Throwable $e) {
                $this->recoverManager();
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

    /**
     * The same number for several migrations at once, in one query.
     *
     * A screen that lists every migration wants all of them, and asking one at
     * a time is the N+1 that took the import page down: eighteen registered
     * migrations meant eighteen reads of the map, and Law 6.1 stops at ten.
     *
     * @param iterable<MigrationInterface> $migrations
     *
     * @return array<string, int> migration id => rows already imported
     */
    public function importedCounts(iterable $migrations): array
    {
        $ids = [];

        foreach ($migrations as $migration) {
            $ids[] = $migration->id();
        }

        return $this->map->countsFor(array_values(array_unique($ids)));
    }

    /**
     * Starts a row's query budget over.
     *
     * Law 6.1 counts reads per table per HTTP request, because a page that
     * reads one table eleven times is looping over lazy-loaded relations. An
     * import breaks that premise honestly: it reads the map once and the
     * destination table once or twice FOR EVERY ROW, because that is the work.
     * Left as-is, any import of more than ten rows would be killed by the
     * tripwire — which is what happens the first time anyone imports something
     * real rather than a fixture.
     *
     * Suspending the guard outright would also hide a genuine lazy-load loop
     * inside a destination, so the budget is applied per row instead: ten reads
     * of one table while handling ONE row is still a defect, and still trips.
     */
    private function newRow(): void
    {
        $this->queryCounter?->reset();
    }

    /**
     * A failed flush closes Doctrine's manager. Without this, every later row
     * in the same request reports "The EntityManager is closed" and the rest
     * of the import is lost.
     */
    private function recoverManager(): void
    {
        if ($this->doctrine === null) {
            return;
        }

        $manager = $this->doctrine->getManager();

        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->doctrine->resetManager();
        }
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
            // run would. Destination id is a placeholder unless this row was
            // already imported — later steps in the same dry-run batch look
            // it up, so it must be non-empty.
            $map->record(new MigrationMapRecord(
                $migration->id(),
                $row->sourceId,
                $checksum,
                $destination->entityType(),
                $existing !== null && $existing->destinationId !== ''
                    ? $existing->destinationId
                    : 'dry-run:'.$migration->id().':'.$row->sourceId,
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
     * must not write to it. One ledger is reused for every dry run() in the
     * request so a later migration can look up rows this request previewed.
     */
    private function mapFor(MigrationInterface $migration, bool $dryRun): MigrationMapInterface
    {
        if (!$dryRun) {
            $this->dryRunLedger = null;
            $this->lookup?->previewThrough(null);

            return $this->map;
        }

        if ($this->dryRunLedger === null) {
            $this->dryRunLedger = new ArrayMigrationMap();
            $this->lookup?->previewThrough($this->dryRunLedger);
        }

        foreach ($this->map->entries($migration->id()) as $record) {
            if ($this->dryRunLedger->find($record->migrationId, $record->sourceId) === null) {
                $this->dryRunLedger->record($record);
            }
        }

        return $this->dryRunLedger;
    }
}
