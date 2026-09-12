<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * A migration that cannot describe its source until an operator says where it is.
 *
 * "Import the posts" is not a complete instruction — import them from WHICH
 * export file, into which locale. A plain MigrationInterface bakes its source
 * and destination into the class, which is right for a fixed feed and useless
 * for an importer.
 *
 * withOptions() returns a CONFIGURED CLONE rather than mutating, so the service
 * registered in the container stays a pristine prototype. Two imports of two
 * different files in the same process cannot then contaminate each other, which
 * is the bug this shape exists to make impossible.
 *
 * Options never change id(): the id keys the map table, and a migration whose
 * identity moved with its arguments would lose its own import history the
 * moment an operator corrected a path. The consequence is worth stating
 * plainly — one migration id means one source system. Importing two different
 * WordPress sites means registering two migrations with two ids, because their
 * post ids overlap and a shared map would treat one site's post 41 as the
 * other's.
 */
interface ConfigurableMigrationInterface extends MigrationInterface
{
    /**
     * What this migration needs before it can run.
     *
     * @return list<MigrationOption>
     */
    public function options(): array;

    /**
     * @param array<string, string> $values
     *
     * @throws \InvalidArgumentException when a required option is missing or an unknown one is supplied
     */
    public function withOptions(array $values): static;
}
