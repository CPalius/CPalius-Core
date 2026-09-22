<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Cache\CacheRebuildManager;
use App\Core\Config\ConfigManager;
use App\Core\Database\QueryCounter;
use App\Core\Module\ModuleLifecycleManager;
use App\Core\Module\ModuleManifest;
use App\Core\Module\ModuleRegistry;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Brings an installation up to date after the code changed.
 *
 * The gap this closes: pulling a new version updates files and nothing else.
 * Migrations sit unapplied, a module whose manifest version advanced never gets
 * its upgrade() called — that only happens on re-activation, so an operator had
 * to toggle every module by hand and had no way to know which ones needed it —
 * and exported configuration stays on disk.
 *
 * ORDER IS THE WHOLE POINT
 *   1. migrations      — schema first; everything after may depend on a column
 *   2. update hooks    — data fixes the schema change implies
 *   3. module upgrades — each module's own upgrade(), now that core is current
 *   4. config import   — declarative state, after the code that understands it
 *   5. cache rebuild   — last, so nothing is serving stale metadata
 *
 * RESUMABILITY
 * Every stage records its own progress (the migration table, the hook ledger,
 * the module version setting), so a run interrupted half way can simply be run
 * again: completed work is skipped rather than repeated. That is why the runner
 * collects failures and keeps going where it safely can, instead of aborting
 * the process and leaving the installation in a state nobody has a name for.
 * The one exception is migrations — a failure there stops everything, because
 * every later stage assumes the schema it produced.
 */
final class UpdateRunner
{
    public const STEP_MIGRATIONS = 'migrations';
    public const STEP_HOOKS = 'update-hooks';
    public const STEP_MODULES = 'modules';
    public const STEP_CONFIG = 'config';
    public const STEP_CACHE = 'cache';

    /**
     * @param iterable<UpdateHookInterface> $hooks
     */
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        #[TaggedIterator('cpalius.update.hook')]
        private readonly iterable $hooks,
        private readonly UpdateHookLedger $ledger,
        private readonly ModuleRegistry $modules,
        private readonly ModuleLifecycleManager $lifecycle,
        private readonly ConfigManager $config,
        private readonly CacheRebuildManager $cache,
        // Same binding ModuleRegistry uses; the registry reports modules but
        // does not hand back their manifests, and onActivated() needs one.
        #[Autowire('%kernel.project_dir%/cp-content/modules')]
        private readonly string $modulesDir,
        private readonly TranslatorInterface $translator,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?QueryCounter $queryCounter = null,
    ) {
    }

    /**
     * @return list<UpdateStepResult>
     */
    public function run(bool $dryRun = false): array
    {
        $results = [];

        $this->queryCounter?->reset();
        $migrations = $this->runMigrations($dryRun);
        $results[] = $migrations;

        // Schema failure is the one stop condition: a hook or a module upgrade
        // written against the new columns would fail in a far more confusing way.
        if ($migrations->isFailure()) {
            return $results;
        }

        /*
         * The N+1 guard budgets reads per table per REQUEST, on the premise
         * that one request renders one page. An update breaks that premise
         * honestly: five steps run back to back, and a table legitimately read
         * a few times by each of them crosses the budget without any
         * lazy-loading loop involved. MigrationRunner already resets per
         * imported row for the same reason. Resetting between steps keeps the
         * guard sharp where it still applies — a real loop inside one step
         * still trips it — without failing the update itself.
         */
        foreach ([
            fn (): UpdateStepResult => $this->runHooks($dryRun),
            fn (): UpdateStepResult => $this->runModuleUpgrades($dryRun),
            fn (): UpdateStepResult => $this->importConfig($dryRun),
            fn (): UpdateStepResult => $this->rebuildCache($dryRun),
        ] as $step) {
            $this->queryCounter?->reset();
            $results[] = $step();
        }

        return $results;
    }

    /**
     * Hooks that have not run yet, in release order.
     *
     * @return list<UpdateHookInterface>
     */
    public function pendingHooks(): array
    {
        $pending = [];

        foreach ($this->hooks as $hook) {
            try {
                if (!$this->ledger->hasRun($hook->id())) {
                    $pending[] = $hook;
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Update hook could not be inspected.', ['hook' => $hook::class, 'exception' => $e]);
            }
        }

        // Release order first, then id, so a run that spans several skipped
        // versions still applies them in the sequence they were written.
        usort(
            $pending,
            static fn (UpdateHookInterface $a, UpdateHookInterface $b): int => version_compare($a->version(), $b->version())
                ?: strcmp($a->id(), $b->id()),
        );

        return $pending;
    }

    /**
     * The schema step on its own, for the request that has just written the new
     * files to disk.
     *
     * Everything from that moment on renders new code against whatever the
     * database currently is, and on a release that renames tables that is fatal:
     * 2.0.0 took sites down with "Table 'cp_users' doesn't exist" before the
     * operator could reach the button that would have migrated them. Migrations
     * are the one step safe to run through the old compiled container — they
     * need the DBAL connection and the migration classes on disk, neither of
     * which the container rebuild changes — so they run here and the rest waits.
     */
    public function migrateOnly(bool $dryRun = false): UpdateStepResult
    {
        return $this->runMigrations($dryRun);
    }

    private function runMigrations(bool $dryRun): UpdateStepResult
    {
        try {
            $planner = $this->migrations->getMigrationPlanCalculator();
            $executed = $this->migrations->getMetadataStorage()->getExecutedMigrations();
            $available = $this->migrations->getMigrationRepository()->getMigrations();

            $pending = [];
            foreach ($available->getItems() as $migration) {
                if (!$executed->hasMigration($migration->getVersion())) {
                    $pending[] = $migration->getVersion();
                }
            }

            if ($pending === []) {
                return UpdateStepResult::skipped(self::STEP_MIGRATIONS, $this->t('migrations.none'));
            }

            $labels = array_map(static fn (object $v): string => self::shortVersion((string) $v), $pending);

            if ($dryRun) {
                return UpdateStepResult::applied(
                    self::STEP_MIGRATIONS,
                    $this->t('migrations.would', ['count' => \count($pending)]),
                    $labels,
                );
            }

            $plan = $planner->getPlanUntilVersion(end($pending));
            $configuration = (new MigratorConfiguration())->setAllOrNothing(false);

            $this->migrations->getMigrator()->migrate($plan, $configuration);

            return UpdateStepResult::applied(
                self::STEP_MIGRATIONS,
                $this->t('migrations.done', ['count' => \count($pending)]),
                $labels,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('cp:update could not apply migrations.', ['exception' => $e]);

            return UpdateStepResult::failed(self::STEP_MIGRATIONS, $e->getMessage());
        }
    }

    private function runHooks(bool $dryRun): UpdateStepResult
    {
        $pending = $this->pendingHooks();

        if ($pending === []) {
            return UpdateStepResult::skipped(self::STEP_HOOKS, $this->t('hooks.none'));
        }

        if ($dryRun) {
            return UpdateStepResult::applied(
                self::STEP_HOOKS,
                $this->t('hooks.would', ['count' => \count($pending)]),
                array_map(
                    static fn (UpdateHookInterface $h): string => sprintf('%s (%s) — %s', $h->id(), $h->version(), $h->description()),
                    $pending,
                ),
            );
        }

        $details = [];
        $failures = [];

        foreach ($pending as $hook) {
            try {
                $report = $hook->run();

                // The ledger is written only after the hook returns, so an
                // interrupted hook runs again rather than being recorded as done.
                $this->ledger->markRun($hook->id());

                $details[] = sprintf('%s — %s', $hook->id(), $report ?? 'nothing to do');
            } catch (\Throwable $e) {
                $this->logger?->error('Update hook failed.', ['hook' => $hook->id(), 'exception' => $e]);
                $failures[] = sprintf('%s — %s', $hook->id(), $e->getMessage());
            }
        }

        if ($failures !== []) {
            return UpdateStepResult::failed(
                self::STEP_HOOKS,
                $this->t('hooks.partial', ['failed' => \count($failures), 'ok' => \count($details)]),
                [...$failures, ...$details],
            );
        }

        return UpdateStepResult::applied(self::STEP_HOOKS, $this->t('hooks.done', ['count' => \count($details)]), $details);
    }

    private function runModuleUpgrades(bool $dryRun): UpdateStepResult
    {
        $details = [];
        $failures = [];

        foreach ($this->modules->discoverAllModules() as $module) {
            // Only active modules: an inactive one gets its install()/upgrade()
            // when it is switched on, and a quarantined one must not be touched
            // at all.
            if ($module['status'] !== 'active') {
                continue;
            }

            $dirName = $module['dirName'];
            $installed = $this->lifecycle->installedVersion($dirName);
            $target = $module['version'];
            $versionNeedsUpgrade = $installed !== null
                && $target !== ''
                && $target !== 'unknown'
                && version_compare($installed, $target, '<');

            $manifest = ModuleManifest::fromDirectory($this->modulesDir.'/'.$dirName);

            if (!$manifest instanceof ModuleManifest) {
                if ($versionNeedsUpgrade) {
                    $failures[] = sprintf('%s — module.json could not be read', $dirName);
                }

                continue;
            }

            $label = sprintf('%s %s → %s', $module['name'], $installed ?? '—', $target);

            if ($dryRun) {
                if ($versionNeedsUpgrade) {
                    $details[] = $label;
                }

                continue;
            }

            if ($versionNeedsUpgrade) {
                $outcome = $this->lifecycle->onActivated($manifest);

                if (($outcome['message'] ?? null) !== null) {
                    $failures[] = sprintf('%s — %s', $label, (string) $outcome['message']);
                } else {
                    $details[] = $label;
                }
            }

            // New .sql files can arrive in a core zip while module.json stays
            // put. applyPendingSql is incremental; a version-bump upgrade()
            // that already ran the same files is a no-op here.
            try {
                $ran = $this->lifecycle->applyPendingSql($manifest);
                if ($ran > 0) {
                    $details[] = sprintf('%s — %d sql', $module['name'], $ran);
                }
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s — sql: %s', $module['name'], $e->getMessage());
            }
        }

        if ($failures !== []) {
            return UpdateStepResult::failed(
                self::STEP_MODULES,
                $this->t('modules.failed', ['count' => \count($failures)]),
                [...$failures, ...$details],
            );
        }

        if ($details === []) {
            return UpdateStepResult::skipped(self::STEP_MODULES, $this->t('modules.none'));
        }

        return UpdateStepResult::applied(
            self::STEP_MODULES,
            $this->t($dryRun ? 'modules.would' : 'modules.done', ['count' => \count($details)]),
            $details,
        );
    }

    private function importConfig(bool $dryRun): UpdateStepResult
    {
        try {
            if ($dryRun) {
                $status = $this->config->status();
                $changed = array_values(array_filter(
                    array_keys($status),
                    static fn (string $name): bool => ($status[$name]['changes'] ?? []) !== [],
                ));

                return $changed === []
                    ? UpdateStepResult::skipped(self::STEP_CONFIG, $this->t('config.none'))
                    : UpdateStepResult::applied(self::STEP_CONFIG, $this->t('config.would', ['count' => \count($changed)]), $changed);
            }

            $applied = $this->config->import();

            if ($applied === []) {
                return UpdateStepResult::skipped(self::STEP_CONFIG, $this->t('config.none'));
            }

            // import() answers document name => list of changes applied; the
            // report wants one readable line per document.
            $details = [];
            foreach ($applied as $document => $changes) {
                $details[] = $changes !== []
                    ? sprintf('%s — %s', $document, implode(', ', array_map('strval', $changes)))
                    : (string) $document;
            }

            return UpdateStepResult::applied(
                self::STEP_CONFIG,
                $this->t('config.done', ['count' => \count($applied)]),
                $details,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('cp:update could not import configuration.', ['exception' => $e]);

            return UpdateStepResult::failed(self::STEP_CONFIG, $e->getMessage());
        }
    }

    private function rebuildCache(bool $dryRun): UpdateStepResult
    {
        if ($dryRun) {
            return UpdateStepResult::applied(self::STEP_CACHE, $this->t('cache.would'));
        }

        try {
            $this->cache->clearSymfonyCache();

            return UpdateStepResult::applied(self::STEP_CACHE, $this->t('cache.done'));
        } catch (\Throwable $e) {
            $this->logger?->error('cp:update could not clear the cache.', ['exception' => $e]);

            // Reported, not fatal: a stale cache is a nuisance an operator can
            // clear by hand, and the schema and data work already succeeded.
            return UpdateStepResult::failed(self::STEP_CACHE, $e->getMessage());
        }
    }

    private static function shortVersion(string $version): string
    {
        $position = strrpos($version, '\\');

        return $position === false ? $version : substr($version, $position + 1);
    }

    /**
     * Step summaries are rendered in AACP, so they are translated at the source
     * rather than left as English literals - the same convention
     * PurgeLogEntriesTask already follows. Keys live under
     * aacp.updates.summary.*, in the intl-icu messages catalogue.
     *
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans('aacp.updates.summary.'.$key, $params);
    }
}
