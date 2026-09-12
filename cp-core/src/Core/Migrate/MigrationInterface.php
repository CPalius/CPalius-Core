<?php

declare(strict_types=1);

namespace App\Core\Migrate;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One named import: a source, a destination, and the transform between them.
 *
 * WHY THIS IS A PHP CLASS AND NOT A YAML FILE
 * Drupal's Migrate API — the reference implementation, and the reason this
 * scorecard row exists — defines a migration as YAML referencing plugin ids:
 * a source plugin, a chain of process plugins per field, a destination plugin.
 * It is powerful and almost entirely undiscoverable. The plugin ids are strings
 * with no type behind them, a typo yields a runtime "plugin not found" deep in
 * a run, and transforming a value in a way the plugin set did not anticipate
 * means writing a plugin anyway. Here the transform is just PHP: the IDE
 * completes it, PHPStan checks it, and a developer who knows the language
 * already knows the API.
 *
 * The id is what the map table keys on, so it never changes. Renaming a
 * migration makes its previous run invisible and the next run will import
 * everything a second time.
 */
#[AutoconfigureTag('cpalius.migration')]
interface MigrationInterface
{
    /**
     * Stable identifier, conventionally "<area>.<what>" — "wordpress.posts",
     * "crm.customers". Never rename one that has run.
     */
    public function id(): string;

    /**
     * Human label for command and AACP output.
     */
    public function label(): string;

    /**
     * Ids of migrations that must run first, e.g. posts depend on authors.
     *
     * The runner orders a multi-migration run by this and refuses a cycle
     * rather than picking an arbitrary order and producing dangling references
     * that only surface as missing authors weeks later.
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    public function source(): MigrationSourceInterface;

    public function destination(): MigrationDestinationInterface;

    /**
     * Shapes a source row into what the destination expects.
     *
     * Return null to skip the row deliberately — a draft that should not come
     * across, a spam comment. Skipping is reported separately from failing, so
     * "1 200 rows filtered" never reads as "1 200 rows broken".
     *
     * Throwing is also allowed and is treated as a row failure: the run
     * continues and the row is reported with its source id and the message.
     */
    public function transform(MigrationRow $row): ?MigrationRow;
}
