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

    protected function setUp(): void
    {
        $this->workDir = \dirname(__DIR__, 4).'/var/test-modules/'.bin2hex(random_bytes(6));
        mkdir($this->workDir, 0775, true);
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
        self::assertStringContainsString('not found', $quarantined[0]['reason']);
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
            modulesDir: $this->workDir,
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
            modulesDir: $this->workDir,
        );
    }
}
