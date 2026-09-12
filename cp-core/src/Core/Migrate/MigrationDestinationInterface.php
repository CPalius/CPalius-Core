<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * Where rows land: a Node bundle, a User, a taxonomy term, a CRM record.
 *
 * A destination must be able to UPDATE as well as create, because the second
 * run of an import is the normal case, not the exception — the source keeps
 * living while the migration is being tuned. The runner hands back the
 * destination id it recorded last time; a driver that ignores it and always
 * inserts turns every re-run into duplicated content.
 *
 * delete() exists so rollback is exact rather than best-effort. Drupal's
 * rollback is only as good as what its id map happens to hold; here the map is
 * written by the runner itself, after the write returned, so what it holds is
 * what actually landed.
 */
interface MigrationDestinationInterface
{
    /**
     * One line for --dry-run and status output, e.g. "Node bundle 'post'".
     */
    public function describe(): string;

    /**
     * Entity type key recorded in the map, e.g. "node" or "user". Rollback and
     * reporting group by it, and it is what tells a later reader what the
     * destination ids in the map actually point at.
     */
    public function entityType(): string;

    /**
     * Creates a record, or updates the one previously written for this row.
     *
     * @param string|null $existingId destination id recorded on an earlier run, or null on first import
     *
     * @return string the destination id, which the runner stores in the map
     */
    public function write(MigrationRow $row, ?string $existingId): string;

    /**
     * Removes a record written by this migration.
     *
     * Returns false when the record is already gone — that is not an error, it
     * is the common case when someone deleted it by hand before rolling back.
     */
    public function delete(string $destinationId): bool;
}
