<?php

declare(strict_types=1);

namespace Modules\Importer;

use App\Core\Migrate\ConfigurableMigrationInterface;
use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationOption;
use App\Core\Migrate\MigrationRegistry;

/**
 * What the import screen offers, and what each option actually needs.
 *
 * Everything here is derived from the migration registry rather than declared
 * twice: which systems work, which migrations belong to one, and which options
 * a system's form must ask for. A screen that kept its own copy of that would
 * disagree with the command line the first time either changed.
 */
final class SourceSystemCatalog
{
    public function __construct(
        private readonly MigrationRegistry $registry,
    ) {
    }

    /**
     * Every system, working ones first.
     *
     * @return list<SourceSystem>
     */
    public function all(): array
    {
        $systems = [];

        foreach ($this->declared() as $system) {
            $systems[] = $system->withAvailability($this->migrationsFor($system) !== []);
        }

        usort($systems, static fn (SourceSystem $a, SourceSystem $b): int => [$b->available, $a->label] <=> [$a->available, $b->label]);

        return $systems;
    }

    public function get(string $id): SourceSystem
    {
        foreach ($this->all() as $system) {
            if ($system->id === $id) {
                return $system;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown import source "%s".', $id));
    }

    /**
     * The registered migrations for a system, in the order they must run.
     *
     * @return list<MigrationInterface>
     */
    public function migrationsFor(SourceSystem $system): array
    {
        $ids = [];

        foreach (array_keys($this->registry->all()) as $id) {
            if (str_starts_with($id, $system->migrationPrefix)) {
                $ids[] = $id;
            }
        }

        return $ids === [] ? [] : $this->registry->orderOf($ids);
    }

    /**
     * Every option the system's migrations accept, merged, so one form can
     * configure the whole run. An option required by any migration is required
     * for the system; the command line spreads the values the same way.
     *
     * @return list<MigrationOption>
     */
    public function optionsFor(SourceSystem $system): array
    {
        /** @var array<string, MigrationOption> $merged */
        $merged = [];

        foreach ($this->migrationsFor($system) as $migration) {
            if (!$migration instanceof ConfigurableMigrationInterface) {
                continue;
            }

            foreach ($migration->options() as $option) {
                $existing = $merged[$option->name] ?? null;

                if ($existing === null || (!$existing->required && $option->required)) {
                    $merged[$option->name] = $option;
                }
            }
        }

        // Required first: a form that asks for the optional locale before the
        // export file buries the thing without which nothing works.
        $options = array_values($merged);
        usort($options, static fn (MigrationOption $a, MigrationOption $b): int => [$b->required, $a->name] <=> [$a->required, $b->name]);

        return $options;
    }

    /**
     * The systems this module knows about, working or not.
     *
     * @return list<SourceSystem>
     */
    private function declared(): array
    {
        return [
            new SourceSystem(
                'wordpress',
                'WordPress',
                'wordpress.',
                'simple-icons:wordpress',
                ['importer.brings.authors', 'importer.brings.terms', 'importer.brings.posts', 'importer.brings.media', 'importer.brings.comments'],
            ),
            new SourceSystem(
                'csv',
                'CSV',
                'csv.',
                'heroicons:table-cells',
                ['importer.brings.rows'],
            ),
            new SourceSystem(
                'xenforo',
                'XenForo',
                'xenforo.',
                'heroicons:chat-bubble-left-right',
                ['importer.brings.users', 'importer.brings.threads', 'importer.brings.posts'],
            ),
            new SourceSystem(
                'mybb',
                'MyBB',
                'mybb.',
                'heroicons:chat-bubble-left-right',
                ['importer.brings.users', 'importer.brings.threads', 'importer.brings.posts'],
            ),
            new SourceSystem(
                'drupal',
                'Drupal',
                'drupal.',
                'simple-icons:drupal',
                ['importer.brings.users', 'importer.brings.terms', 'importer.brings.nodes'],
            ),
            new SourceSystem(
                'joomla',
                'Joomla',
                'joomla.',
                'simple-icons:joomla',
                ['importer.brings.users', 'importer.brings.terms', 'importer.brings.articles'],
            ),
        ];
    }
}
