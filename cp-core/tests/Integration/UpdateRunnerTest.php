<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Cache\CacheRebuildManager;
use App\Core\Config\ConfigManager;
use App\Core\Module\ModuleLifecycleManager;
use App\Core\Module\ModuleRegistry;
use App\Core\Update\UpdateHookInterface;
use App\Core\Update\UpdateHookLedger;
use App\Core\Update\UpdateRunner;
use App\Core\Update\UpdateStepResult;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * T3.6 — the update runner.
 *
 * Built with real collaborators (they are all final) plus fixture hooks, so the
 * ordering, the ledger and the failure handling are exercised against the same
 * services production uses.
 */
#[CoversClass(UpdateRunner::class)]
#[CoversClass(UpdateHookLedger::class)]
final class UpdateRunnerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        RecordingHook::$ran = [];
    }

    public function testStepsRunInTheDeclaredOrder(): void
    {
        $results = $this->runner()->run(dryRun: true);

        // Schema before data before modules before config before cache: every
        // later stage may depend on what an earlier one produced.
        self::assertSame(
            [
                UpdateRunner::STEP_MIGRATIONS,
                UpdateRunner::STEP_HOOKS,
                UpdateRunner::STEP_MODULES,
                UpdateRunner::STEP_CONFIG,
                UpdateRunner::STEP_CACHE,
            ],
            array_map(static fn (UpdateStepResult $r): string => $r->step, $results),
        );
    }

    public function testDryRunChangesNothing(): void
    {
        $runner = $this->runner(new RecordingHook('core.1_0_0.alpha', '1.0.0'));

        $results = $runner->run(dryRun: true);

        self::assertSame([], RecordingHook::$ran, 'a dry run must not execute a hook');
        self::assertFalse($this->ledger()->hasRun('core.1_0_0.alpha'), 'and must not record one');

        $hooks = $this->step($results, UpdateRunner::STEP_HOOKS);
        self::assertTrue($hooks->changedAnything(), 'but it still reports what would happen');
        self::assertStringContainsString('would run', $hooks->summary);
    }

    public function testHooksRunOnceAndAreSkippedOnASecondRun(): void
    {
        $runner = $this->runner(new RecordingHook('core.1_0_0.alpha', '1.0.0'));

        $first = $runner->run();
        self::assertSame(['core.1_0_0.alpha'], RecordingHook::$ran);
        self::assertTrue($this->step($first, UpdateRunner::STEP_HOOKS)->changedAnything());

        // The ledger is what makes a re-run safe: this is the property an
        // operator relies on after an interrupted deploy.
        $second = $runner->run();
        self::assertSame(['core.1_0_0.alpha'], RecordingHook::$ran, 'the hook did not run twice');
        self::assertFalse($this->step($second, UpdateRunner::STEP_HOOKS)->changedAnything());
    }

    public function testHooksApplyInReleaseOrderAcrossSkippedVersions(): void
    {
        // Registered out of order on purpose: upgrading from 1.0 to 1.2 in one
        // step must still apply 1.1 before 1.2.
        $runner = $this->runner(
            new RecordingHook('core.1_2_0.third', '1.2.0'),
            new RecordingHook('core.1_0_0.first', '1.0.0'),
            new RecordingHook('core.1_1_0.second', '1.1.0'),
        );

        $runner->run();

        self::assertSame(
            ['core.1_0_0.first', 'core.1_1_0.second', 'core.1_2_0.third'],
            RecordingHook::$ran,
        );
    }

    public function testAFailingHookIsReportedAndDoesNotStopTheOthers(): void
    {
        $runner = $this->runner(
            new RecordingHook('core.1_0_0.before', '1.0.0'),
            new ThrowingHook('core.1_1_0.broken', '1.1.0'),
            new RecordingHook('core.1_2_0.after', '1.2.0'),
        );

        $results = $runner->run();
        $hooks = $this->step($results, UpdateRunner::STEP_HOOKS);

        self::assertTrue($hooks->isFailure());
        self::assertStringContainsString('1 hook(s) failed', $hooks->summary);

        // The neighbours still ran: one broken hook must not block the release.
        self::assertSame(['core.1_0_0.before', 'core.1_2_0.after'], RecordingHook::$ran);

        // And the broken one is NOT recorded, so a fixed version runs next time.
        self::assertFalse($this->ledger()->hasRun('core.1_1_0.broken'));
        self::assertTrue($this->ledger()->hasRun('core.1_0_0.before'));

        // A hook failure does not abort the pipeline: the cache step still ran,
        // so the release is not left half-applied because one data fix broke.
        self::assertTrue($this->step($results, UpdateRunner::STEP_CACHE)->changedAnything());
    }

    public function testACorruptLedgerIsTreatedAsEmptyRatherThanComplete(): void
    {
        $ledger = $this->ledger();
        $ledger->markRun('core.1_0_0.alpha');
        self::assertTrue($ledger->hasRun('core.1_0_0.alpha'));

        // Fail-safe direction: unreadable bookkeeping must make hooks run again
        // (they are required to be idempotent), never silently skip them.
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            'UPDATE cp_settings SET setting_value = :broken WHERE setting_key = :key',
            ['broken' => 'not json at all', 'key' => 'update.core.applied_hooks'],
        );

        // The raw UPDATE bypassed the ORM, so the identity map still holds the
        // old row; clearing it forces a genuine read.
        $this->em()->clear();

        self::assertSame([], $this->freshLedger()->applied());
    }

    /**
     * @param list<UpdateStepResult> $results
     */
    private function step(array $results, string $step): UpdateStepResult
    {
        foreach ($results as $result) {
            if ($result->step === $step) {
                return $result;
            }
        }

        self::fail(sprintf('Step "%s" is missing from the run.', $step));
    }

    private function runner(UpdateHookInterface ...$hooks): UpdateRunner
    {
        $container = $this->container();
        $this->stampMigrationsAsExecuted();

        return new UpdateRunner(
            $container->get('doctrine.migrations.dependency_factory'),
            $hooks,
            $this->ledger(),
            $container->get(ModuleRegistry::class),
            $container->get(ModuleLifecycleManager::class),
            $container->get(ConfigManager::class),
            $container->get(CacheRebuildManager::class),
            $this->kernelModulesDir(),
            // UpdateRunner gained a translator when its step summaries stopped
            // being English string literals; the real one from the container
            // keeps this test asserting on the same text the panel renders.
            $container->get(TranslatorInterface::class),
        );
    }

    /**
     * The test schema is built by SchemaTool, so the migration table is empty
     * even though every table exists. Left alone, the runner would correctly
     * try to apply 60 migrations against tables that are already there and
     * fail — which is right in production and useless here. Stamping them as
     * executed makes the fixture represent a migrated installation, which is
     * what the later steps are being tested against.
     */
    private function stampMigrationsAsExecuted(): void
    {
        /** @var DependencyFactory $factory */
        $factory = $this->container()->get('doctrine.migrations.dependency_factory');

        /** @var MetadataStorage $storage */
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $executed = $storage->getExecutedMigrations();

        foreach ($factory->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            if (!$executed->hasMigration($migration->getVersion())) {
                $storage->complete(new ExecutionResult($migration->getVersion(), Direction::UP));
            }
        }
    }

    private function ledger(): UpdateHookLedger
    {
        /** @var UpdateHookLedger $ledger */
        $ledger = $this->container()->get(UpdateHookLedger::class);

        return $ledger;
    }

    /**
     * A second instance, to prove the read comes from the database rather than
     * the memo of the instance that wrote it.
     */
    private function freshLedger(): UpdateHookLedger
    {
        return new UpdateHookLedger(
            $this->container()->get(\App\Repository\SettingRepository::class),
            $this->em(),
            $this->container()->get(\App\Core\Settings\SettingsRegistry::class),
        );
    }

    private function kernelModulesDir(): string
    {
        return \dirname(__DIR__, 3).'/cp-content/modules';
    }
}

/**
 * Records the order hooks ran in. Static, because the runner is handed the
 * instances directly and the assertions need to see across them.
 */
final class RecordingHook implements UpdateHookInterface
{
    /** @var list<string> */
    public static array $ran = [];

    public function __construct(
        private readonly string $id,
        private readonly string $version,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): string
    {
        return 'Fixture hook '.$this->id;
    }

    public function run(): string
    {
        self::$ran[] = $this->id;

        return 'recorded';
    }
}

final class ThrowingHook implements UpdateHookInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $version,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): string
    {
        return 'Fixture hook that fails';
    }

    public function run(): ?string
    {
        throw new \RuntimeException('deliberate hook failure');
    }
}
