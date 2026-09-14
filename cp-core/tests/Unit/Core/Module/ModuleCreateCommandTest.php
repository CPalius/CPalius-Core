<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Command\ModuleCreateCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ModuleCreateCommand::class)]
final class ModuleCreateCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-module-create-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/cp-content/modules', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testRejectsInvalidName(): void
    {
        $tester = new CommandTester(new ModuleCreateCommand($this->projectDir));
        $status = $tester->execute(['name' => 'not-valid']);

        self::assertSame(1, $status);
        self::assertStringContainsString('PascalCase', $tester->getDisplay());
    }

    public function testScaffoldsManifestInstallerAndTranslations(): void
    {
        $tester = new CommandTester(new ModuleCreateCommand($this->projectDir));
        $status = $tester->execute(['name' => 'Portfolio']);

        self::assertSame(0, $status);

        $root = $this->projectDir.'/cp-content/modules/Portfolio';
        self::assertFileExists($root.'/module.json');
        self::assertFileExists($root.'/PortfolioModule.php');
        self::assertFileExists($root.'/Install/ModuleInstaller.php');
        self::assertFileExists($root.'/Resources/migrations/.gitkeep');
        self::assertFileExists($root.'/Resources/translations/messages+intl-icu.en.yaml');
        self::assertFileExists($root.'/Resources/config/contributions.yaml');
        self::assertFileExists($root.'/Resources/config/importmap.php');
        self::assertFileExists($root.'/Install/ModuleInstaller.php');

        $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);
        self::assertIsArray($manifest);
        self::assertSame('Modules\\Portfolio\\PortfolioModule', $manifest['bundle']);
        self::assertSame('^1.0', $manifest['core_version']);
    }
}
