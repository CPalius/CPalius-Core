<?php

declare(strict_types=1);

namespace App\Core\Update;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A one-off piece of work that must run once when upgrading past a version.
 *
 * Migrations change the schema; update hooks change DATA, or anything else a
 * DDL statement cannot express — re-indexing a flat index after a field type
 * changed, rewriting a settings value, backfilling a column a migration just
 * added. Doctrine has no place for that, which is how such work historically
 * ends up as a README instruction that nobody performs.
 *
 * Contract:
 *   - Identified by an id that never changes, because the id is what records
 *     "already done". Renaming one makes it run a second time.
 *   - Tagged with the version it belongs to, so hooks apply in release order
 *     even when several versions are skipped at once.
 *   - Idempotent anyway. The ledger is written after a hook returns, so a crash
 *     mid-hook means it runs again on the next attempt; a hook that cannot
 *     survive that will corrupt data on exactly the run where things were
 *     already going wrong.
 */
#[AutoconfigureTag('cpalius.update.hook')]
interface UpdateHookInterface
{
    /**
     * Stable identifier, conventionally "<area>.<version>.<what>" —
     * for example "core.1_1_0.reindex_queryable_fields".
     */
    public function id(): string;

    /**
     * The release this hook belongs to, as a version_compare()-able string.
     */
    public function version(): string;

    /**
     * One line describing what it does, shown before it runs in --dry-run.
     */
    public function description(): string;

    /**
     * Performs the work and returns a short report of what changed, or null
     * when it found nothing to do.
     */
    public function run(): ?string;
}
