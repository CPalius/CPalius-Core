<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Module\ModuleRegistry;
use Modules\BrokenBoot\BrokenBootModule;
use Modules\Healthy\HealthyModule;
use Modules\NotABundle\NotABundleModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Manifesto Law 2.1 + 2.2 — unit test for module quarantine logic.
 * Uses temporary active_modules.php files; real config is never touched.
 */
#[CoversClass(ModuleRegistry::class)]
final class ModuleRegistryTest extends TestCase
{
    private string $workDir;

    /**
     * Where the fixture module classes below actually live on disk (the
     * autoload-dev PSR-4 root for `Modules\`). ModuleRegistry now verifies a
     * module's file exists under modulesDir before trusting class_exists(),
     * so tests exercising "healthy" fixtures must point modulesDir here, not
     * at the empty scratch workDir.
     */
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->workDir = \dirname(__DIR__, 4).'/var/test-modules/'.bin2hex(random_bytes(6));
        mkdir($this->workDir, 0775, true);
        $this->fixturesDir = \dirname(__DIR__, 4).'/tests/Fixtures/Modules';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    /** Control group: a healthy module must stay in the list. */
    public function testHealthyModuleIsKept(): void
    {
        $registry = $this->createRegistry([HealthyModule::class]);

        self::assertSame([HealthyModule::class], $registry->getHealthyModuleBundles());
        self::assertSame([], $registry->getQuarantinedModules());
    }

    /** Law 2.2: a class not implementing BundleInterface must be quarantined. */
    public function testClassNotImplementingBundleInterfaceIsQuarantined(): void
    {
        $registry = $this->createRegistry([NotABundleModule::class]);

        self::assertSame([], $registry->getHealthyModuleBundles());

        $quarantined = $registry->getQuarantinedModules();
        self::assertCount(1, $quarantined);
        self::assertSame(NotABundleModule::class, $quarantined[0]['class']);
        self::assertStringContainsString('BundleInterface', $quarantined[0]['reason']);
    }

    public function testMissingClassIsQuarantined(): void
    {
        $registry = $this->createRegistry(['Modules\\Hayalet\\HayaletModule']);

        self::assertSame([], $registry->getHealthyModuleBundles());

        $quarantined = $registry->getQuarantinedModules();
        self::assertCount(1, $quarantined);
        self::assertStringContainsString('missing on disk', $quarantined[0]['reason']);
    }

    /** Isolation essence: a broken module must not affect healthy siblings. */
    public function testBrokenModuleDoesNotAffectHealthySiblings(): void
    {
        $registry = $this->createRegistry([
            HealthyModule::class,
            NotABundleModule::class,
            'Modules\\Hayalet\\HayaletModule',
            BrokenBootModule::class,
        ]);

        $healthy = $registry->getHealthyModuleBundles();

        // BrokenBootModule is healthy here — runtime failures are Kernel::boot()'s job.
        self::assertSame([HealthyModule::class, BrokenBootModule::class], $healthy);

        $quarantinedClasses = array_column($registry->getQuarantinedModules(), 'class');
        self::assertContains(NotABundleModule::class, $quarantinedClasses);
        self::assertContains('Modules\\Hayalet\\HayaletModule', $quarantinedClasses);
    }

    /** Edge case: corrupt active_modules.php — system survives with no modules. */
    public function testCorruptActiveModulesFileDisablesModulesButDoesNotThrow(): void
    {
        $file = $this->workDir.'/active_modules.php';
        file_put_contents($file, "<?php\nreturn 'bu bir dizi degil';\n");

        $registry = new ModuleRegistry(
            activeModulesFile: $file,
            quarantineLogFile: $this->workDir.'/quarantine.log',
            modulesDir: $this->workDir,
        );

        self::assertSame([], $registry->getHealthyModuleBundles());
    }

    public function testMissingActiveModulesFileYieldsEmptyListWithoutError(): void
    {
        $registry = new ModuleRegistry(
            activeModulesFile: $this->workDir.'/olmayan.php',
            quarantineLogFile: $this->workDir.'/quarantine.log',
            modulesDir: $this->workDir,
        );

        self::assertSame([], $registry->getHealthyModuleBundles());
        self::assertSame([], $registry->getQuarantinedModules());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEntryProvider(): iterable
    {
        yield 'bos dize' => [''];
        yield 'null' => [null];
        yield 'sayi' => [42];
    }

    public function testInvalidEntriesAreQuarantinedNotFatal(): void
    {
        $file = $this->workDir.'/active_modules.php';
        file_put_contents($file, "<?php\nreturn ['', null, 42, ".var_export(HealthyModule::class, true)."];\n");

        $registry = new ModuleRegistry(
            activeModulesFile: $file,
            quarantineLogFile: $this->workDir.'/quarantine.log',
            modulesDir: $this->fixturesDir,
        );

        // Invalid entries are filtered; valid ones survive.
        self::assertSame([HealthyModule::class], $registry->getHealthyModuleBundles());
        self::assertCount(3, $registry->getQuarantinedModules());
    }

    /** Quarantine must be logged so admins can diagnose missing modules (Law 2.3). */
    public function testQuarantineIsWrittenToLogFile(): void
    {
        $logFile = $this->workDir.'/quarantine.log';

        $registry = $this->createRegistry([NotABundleModule::class], $logFile);
        $registry->getHealthyModuleBundles();

        self::assertFileExists($logFile);

        $log = (string) file_get_contents($logFile);
        self::assertStringContainsString(NotABundleModule::class, $log);
        self::assertStringContainsString('quarantined', $log);
    }

    /**
     * @param list<string> $moduleClasses
     */
    private function createRegistry(array $moduleClasses, ?string $logFile = null): ModuleRegistry
    {
        $file = $this->workDir.'/active_modules.php';

        $entries = implode(",\n    ", array_map(
            static fn (string $class): string => var_export($class, true),
            $moduleClasses,
        ));

        file_put_contents($file, "<?php\n\nreturn [\n    ".$entries.",\n];\n");

        return new ModuleRegistry(
            activeModulesFile: $file,
            quarantineLogFile: $logFile ?? $this->workDir.'/quarantine.log',
            modulesDir: $this->fixturesDir,
        );
    }

    /**
     * The actual production incident this check exists for: an operator
     * deletes a module's directory by hand (FTP) without deactivating it
     * first, so active_modules.php still declares it. class_exists() alone
     * cannot catch this under an optimized Composer classmap (built before
     * the deletion, never regenerated) — it would still report true. The
     * file-existence check must catch it regardless of what class_exists()
     * says, using a module directory that mimics production's real layout
     * (a class file directly under modulesDir/<ModuleName>/) rather than the
     * fixtures dir, which class_exists() can already resolve.
     */
    public function testModuleFileDeletedAfterActivationIsQuarantinedEvenThoughClassStillLoads(): void
    {
        // A distinct, throwaway class (not one of the shared fixtures) so
        // this test does not depend on / interfere with class_exists()
        // caching for HealthyModule elsewhere in this suite.
        $moduleDir = $this->workDir.'/GonePhpUnit';
        mkdir($moduleDir, 0775, true);
        $classFile = $moduleDir.'/GonePhpUnitModule.php';
        file_put_contents($classFile, <<<'PHP'
            <?php
            namespace Modules\GonePhpUnit;
            final class GonePhpUnitModule extends \Symfony\Component\HttpKernel\Bundle\Bundle {}
            PHP);

        // Load it once so the autoloader/opcache knows the class — simulating
        // an optimized classmap generated while the file still existed.
        require $classFile;
        self::assertTrue(class_exists('Modules\\GonePhpUnit\\GonePhpUnitModule', false));

        // Now simulate the operator deleting the module's files by hand.
        unlink($classFile);
        rmdir($moduleDir);

        // Not createRegistry(): this test's whole point is a module dir that
        // matches where the (now-deleted) file actually was, not the shared
        // fixtures dir — createRegistry()'s modulesDir wouldn't have had it
        // either way, which would pass for the wrong reason.
        $activeModulesFile = $this->workDir.'/active_modules.php';
        file_put_contents(
            $activeModulesFile,
            "<?php\nreturn ['Modules\\\\GonePhpUnit\\\\GonePhpUnitModule'];\n",
        );
        $registry = new ModuleRegistry(
            activeModulesFile: $activeModulesFile,
            quarantineLogFile: $this->workDir.'/quarantine.log',
            modulesDir: $this->workDir,
        );

        self::assertSame([], $registry->getHealthyModuleBundles());
        $quarantined = $registry->getQuarantinedModules();
        self::assertCount(1, $quarantined);
        self::assertStringContainsString('missing on disk', $quarantined[0]['reason']);
    }
}
