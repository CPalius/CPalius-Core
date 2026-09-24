<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Install;

use App\Core\Install\DatabaseUrl;
use App\Core\Install\EnvironmentWriter;
use App\Core\Install\InstallCleaner;
use App\Core\Install\InstallGate;
use App\Core\Install\InstallRunner;
use App\Core\Install\RequirementChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstallGate::class)]
#[CoversClass(DatabaseUrl::class)]
#[CoversClass(EnvironmentWriter::class)]
#[CoversClass(InstallRunner::class)]
#[CoversClass(RequirementChecker::class)]
#[CoversClass(InstallCleaner::class)]
final class InstallWizardTest extends TestCase
{
    public function testAFreshTreeNeedsTheWizard(): void
    {
        $dir = $this->tree();

        self::assertFalse(InstallGate::shouldBootApplication($dir));
    }

    public function testAConfiguredEnvBootsWithoutALock(): void
    {
        $dir = $this->tree();
        file_put_contents($dir.'/.env', "APP_SECRET=abc\nDATABASE_URL=\"mysql://app:secret@127.0.0.1:3306/app?serverVersion=8.0.32&charset=utf8mb4\"\n");

        self::assertTrue(InstallGate::shouldBootApplication($dir));
    }

    public function testExamplePlaceholdersAreNotAnInstallation(): void
    {
        $dir = $this->tree();
        file_put_contents($dir.'/.env', "APP_SECRET=abc\nDATABASE_URL=\"mysql://kullanici:parola@127.0.0.1:3306/cpalius?serverVersion=8.0.32&charset=utf8mb4\"\n");

        self::assertFalse(InstallGate::shouldBootApplication($dir));
    }

    public function testAnInProgressInstallDoesNotBootTheHalfWrittenEnv(): void
    {
        $dir = $this->tree();
        file_put_contents($dir.'/.env', "APP_SECRET=abc\nDATABASE_URL=\"mysql://app:secret@127.0.0.1:3306/app?serverVersion=8.0.32&charset=utf8mb4\"\n");
        InstallGate::markInstalling($dir);

        self::assertFalse(InstallGate::shouldBootApplication($dir));

        InstallGate::markInstalled($dir, ['installed_at' => 'now']);
        self::assertTrue(InstallGate::shouldBootApplication($dir));
    }

    public function testAFailedInstallMayKeepOnlyTheMigrationTable(): void
    {
        self::assertTrue(DatabaseUrl::isInstallableDatabase([]));
        self::assertTrue(DatabaseUrl::isInstallableDatabase(['doctrine_migration_versions']));
        self::assertFalse(DatabaseUrl::isInstallableDatabase(['doctrine_migration_versions', 'cp_users']));
    }

    public function testServerVersionDistinguishesMariadb(): void
    {
        self::assertSame('mariadb-11.4.2', DatabaseUrl::serverVersionFromBanner('11.4.2-MariaDB-1'));
        self::assertSame('8.0.36', DatabaseUrl::serverVersionFromBanner('8.0.36'));
    }

    public function testDatabaseUrlEncodesCredentials(): void
    {
        $url = DatabaseUrl::toDatabaseUrl('127.0.0.1', 3306, 'cpalius', 'app', 'p@ss:word', 'mariadb-11.4.2');

        self::assertStringContainsString('app:p%40ss%3Aword@127.0.0.1:3306/cpalius', $url);
        self::assertStringContainsString('serverVersion=mariadb-11.4.2', $url);
    }

    public function testEnvironmentWriterReplacesOwnedKeysAndKeepsTheRest(): void
    {
        $dir = $this->tree();
        file_put_contents($dir.'/.env.example', "APP_ENV=prod\nAPP_SECRET=\nDATABASE_URL=\"mysql://kullanici:parola@127.0.0.1:3306/cpalius\"\nMAILER_DSN=null://default\n");

        (new EnvironmentWriter())->write($dir, [
            'APP_SECRET' => 'sekret',
            'DATABASE_URL' => 'mysql://app:secret@127.0.0.1:3306/cpalius?serverVersion=8.0.32&charset=utf8mb4',
        ]);

        $written = (string) file_get_contents($dir.'/.env');
        self::assertStringContainsString('APP_SECRET="sekret"', $written);
        self::assertStringContainsString('MAILER_DSN=null://default', $written);
        self::assertStringNotContainsString('kullanici:parola', $written);
        self::assertFileDoesNotExist($dir.'/.env.installing');
    }

    public function testPasswordRulesRejectShortAndIdentityPasswords(): void
    {
        $runner = new InstallRunner();

        self::assertNotEmpty($runner->passwordErrors('short', []));
        self::assertNotEmpty($runner->passwordErrors('secret-admin@example.com-X1', ['admin@example.com']));
        self::assertSame([], $runner->passwordErrors('Correct-horse-9', ['admin@example.com', 'ada']));
    }

    public function testCleanupRemovesTheWizardTree(): void
    {
        $dir = $this->tree();
        mkdir($dir.'/cp-core/install/views', 0775, true);
        file_put_contents($dir.'/cp-core/install/web.php', '<?php ');

        $left = (new InstallCleaner())->cleanup($dir);

        self::assertSame([], $left);
        self::assertDirectoryDoesNotExist($dir.'/cp-core/install');
    }

    public function testThisCheckoutBootsBecauseEnvIsAlreadyConfigured(): void
    {
        $projectDir = dirname(__DIR__, 5);

        self::assertTrue(InstallGate::shouldBootApplication($projectDir));
    }

    private function tree(): string
    {
        $dir = sys_get_temp_dir().'/cpalius-install-'.bin2hex(random_bytes(4));
        mkdir($dir.'/cp-core/var', 0775, true);
        file_put_contents($dir.'/.env.example', "APP_SECRET=\n");

        return $dir;
    }
}
