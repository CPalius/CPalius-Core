<?php

declare(strict_types=1);

namespace App\Core\Migrate;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Every migration the installation knows about, in an order that is safe to run.
 *
 * Ordering matters more than it looks: posts reference authors, order lines
 * reference orders, and an import that runs them in discovery order produces
 * dangling references that surface weeks later as content with no author. So
 * dependsOn() is resolved here, and a cycle is a hard error rather than an
 * arbitrary pick — being told "these two migrations depend on each other" at
 * the start is recoverable, discovering it from half-imported data is not.
 *
 * An unknown dependency is also refused. A typo in a dependency id would
 * otherwise mean the dependency is silently ignored, which is precisely the
 * ordering bug the list exists to prevent.
 */
final class MigrationRegistry
{
    /** @var array<string, MigrationInterface>|null */
    private ?array $migrations = null;

    /**
     * @param iterable<MigrationInterface> $taggedMigrations
     */
    public function __construct(
        #[TaggedIterator('cpalius.migration')]
        private readonly iterable $taggedMigrations = [],
    ) {
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function get(string $id): MigrationInterface
    {
        $migration = $this->all()[$id] ?? null;

        if ($migration === null) {
            throw new \InvalidArgumentException(sprintf('Unknown migration "%s". Known migrations: %s.', $id, $this->all() === [] ? '(none registered)' : implode(', ', array_keys($this->all()))));
        }

        return $migration;
    }

    /**
     * @return array<string, MigrationInterface>
     */
    public function all(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $migrations = [];

        foreach ($this->taggedMigrations as $migration) {
            $id = $migration->id();

            if (isset($migrations[$id])) {
                throw new \LogicException(sprintf('Two migrations claim the id "%s" (%s and %s). The id keys the map table, so duplicates would share each other\'s import history.', $id, $migrations[$id]::class, $migration::class));
            }

            $migrations[$id] = $migration;
        }

        return $this->migrations = $migrations;
    }

    /**
     * All migrations, dependencies before dependents.
     *
     * @return list<MigrationInterface>
     */
    public function ordered(): array
    {
        return $this->orderOf(array_keys($this->all()));
    }

    /**
     * The given migrations plus whatever they depend on, in runnable order.
     *
     * Pulling in dependencies is deliberate: asking to run "posts" and getting
     * posts without their authors is not what anyone means.
     *
     * @param list<string> $ids
     *
     * @return list<MigrationInterface>
     */
    public function orderOf(array $ids): array
    {
        $resolved = [];
        $visiting = [];

        foreach ($ids as $id) {
            $this->visit($id, $resolved, $visiting, []);
        }

        return array_values($resolved);
    }

    /**
     * @param array<string, MigrationInterface> $resolved
     * @param array<string, true>               $visiting
     * @param list<string>                      $path
     */
    private function visit(string $id, array &$resolved, array &$visiting, array $path): void
    {
        if (isset($resolved[$id])) {
            return;
        }

        if (isset($visiting[$id])) {
            throw new \LogicException(sprintf('Migration dependencies form a cycle: %s. Break it before running; there is no safe order.', implode(' -> ', [...$path, $id])));
        }

        $migration = $this->get($id);
        $visiting[$id] = true;

        foreach ($migration->dependsOn() as $dependency) {
            if (!$this->has($dependency)) {
                throw new \LogicException(sprintf('Migration "%s" depends on "%s", which is not registered. A dependency that cannot be found would be silently skipped, producing exactly the dangling references dependsOn() exists to prevent.', $id, $dependency));
            }

            $this->visit($dependency, $resolved, $visiting, [...$path, $id]);
        }

        unset($visiting[$id]);
        $resolved[$id] = $migration;
    }
}
