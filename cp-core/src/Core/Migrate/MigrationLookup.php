<?php

declare(strict_types=1);

namespace App\Core\Migrate;

use App\Core\Migrate\Map\MigrationMapInterface;

/**
 * Answers "what did the authors migration turn WordPress user 3 into?".
 *
 * This is what makes an import of more than one entity type possible at all.
 * A post carries its author as a foreign login and its categories as foreign
 * term ids; without a way to translate those into CPalius ids, every importer
 * ends up either re-resolving by title (which silently merges two different
 * authors who share a display name) or importing posts with no author at all.
 *
 * The map already holds the answer — this exposes it as a first-class service
 * so a migration's transform can ask, and so the "dependency must run first"
 * rule in MigrationRegistry has something concrete to protect.
 */
final class MigrationLookup
{
    /**
     * Rows a dry-run batch has already "imported" in memory. Consulted first
     * so a later step (topics after sections) can resolve parents that were
     * not written to the persistent map.
     */
    private ?MigrationMapInterface $preview = null;

    public function __construct(
        private readonly MigrationMapInterface $map,
    ) {
    }

    public function previewThrough(?MigrationMapInterface $map): void
    {
        $this->preview = $map;
    }

    /**
     * The destination id a previous migration recorded for this source id, or
     * null when that row was never imported (skipped, failed, or not yet run).
     */
    public function find(string $migrationId, string $sourceId): ?string
    {
        if (trim($sourceId) === '') {
            return null;
        }

        $preview = $this->preview?->find($migrationId, $sourceId);
        if ($preview !== null) {
            return $preview->destinationId;
        }

        return $this->map->find($migrationId, $sourceId)?->destinationId;
    }

    public function findInt(string $migrationId, string $sourceId): ?int
    {
        $id = $this->find($migrationId, $sourceId);

        return $id === null || !is_numeric($id) ? null : (int) $id;
    }

    /**
     * Like find(), but says why it could not rather than returning null into a
     * transform that will then write a record with a missing reference.
     */
    public function require(string $migrationId, string $sourceId): string
    {
        $id = $this->find($migrationId, $sourceId);

        if ($id === null) {
            throw new \RuntimeException(sprintf('Migration "%s" has no record for source id "%s". Either that row failed or was skipped, or this migration ran before the one it depends on.', $migrationId, $sourceId));
        }

        return $id;
    }

    /**
     * @param list<string> $sourceIds
     *
     * @return list<string> destination ids for the ones that were imported, in order, silently dropping the rest
     */
    public function findAll(string $migrationId, array $sourceIds): array
    {
        $found = [];

        foreach ($sourceIds as $sourceId) {
            $id = $this->find($migrationId, $sourceId);

            if ($id !== null) {
                $found[] = $id;
            }
        }

        return $found;
    }
}
