<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Backup;

use App\Core\Backup\BackupException;
use App\Core\Backup\BackupFileCollector;
use App\Core\Backup\BackupFilename;
use App\Core\Backup\BackupService;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

#[CoversClass(BackupFilename::class)]
#[CoversClass(BackupFileCollector::class)]
#[CoversClass(BackupService::class)]
final class BackupServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-backup-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/cp-content/modules', 0775, true);
        mkdir($this->projectDir.'/cp-core/src', 0775, true);
        mkdir($this->projectDir.'/cp-core/var/backups', 0775, true);
        mkdir($this->projectDir.'/cp-includes/vendor/pkg', 0775, true);
        mkdir($this->projectDir.'/public/uploads', 0775, true);
        mkdir($this->projectDir.'/public/assets', 0775, true);
        mkdir($this->projectDir.'/public/page-cache', 0775, true);
        mkdir($this->projectDir.'/cp-core/var/cache/dev', 0775, true);

        file_put_contents($this->projectDir.'/composer.json', '{"name":"cpalius/test"}');
        file_put_contents($this->projectDir.'/.env', 'APP_SECRET=hidden');
        file_put_contents($this->projectDir.'/cp-content/modules/keep.txt', 'module-ok');
        file_put_contents($this->projectDir.'/cp-core/src/Kernel.php', '<?php');
        file_put_contents($this->projectDir.'/cp-includes/vendor/pkg/skip.php', 'vendor-yes');
        file_put_contents($this->projectDir.'/public/uploads/photo.txt', 'upload-ok');
        file_put_contents($this->projectDir.'/public/assets/app.css', 'asset-ok');
        file_put_contents($this->projectDir.'/public/index.php', '<?php');
        file_put_contents($this->projectDir.'/public/page-cache/index.html', 'cache-no');
        file_put_contents($this->projectDir.'/cp-core/var/cache/dev/app.php', 'cache-no');
        file_put_contents($this->projectDir.'/cp-core/var/backups/nested.zip', 'backup-no');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function filenameProvider(): iterable
    {
        yield 'db dump' => ['cpalius-db-20260909-141500.sql.gz', true];
        yield 'files zip' => ['cpalius-files-20260909-141500.zip', true];
        yield 'full zip' => ['cpalius-full-20260909-141500.zip', true];
        yield 'path traversal' => ['../cpalius-db-20260909-141500.sql.gz', false];
        yield 'absolute' => ['C:/tmp/cpalius-db-20260909-141500.sql.gz', false];
        yield 'db as zip' => ['cpalius-db-20260909-141500.zip', false];
        yield 'files as sql' => ['cpalius-files-20260909-141500.sql.gz', false];
        yield 'random' => ['notes.txt', false];
    }

    #[DataProvider('filenameProvider')]
    public function testFilenameAllowlist(string $filename, bool $expected): void
    {
        self::assertSame($expected, BackupFilename::isValid($filename));
    }

    public function testStemRoundTripOmitsStaticExtensions(): void
    {
        self::assertSame('cpalius-db-20260909-141500', BackupFilename::stemFrom('cpalius-db-20260909-141500.sql.gz'));
        self::assertSame('cpalius-files-20260909-141500', BackupFilename::stemFrom('cpalius-files-20260909-141500.zip'));
        self::assertSame('cpalius-db-20260909-141500.sql.gz', BackupFilename::fromStem('cpalius-db-20260909-141500'));
        self::assertSame('cpalius-full-20260909-141500.zip', BackupFilename::fromStem('cpalius-full-20260909-141500'));

        $this->expectException(BackupException::class);
        BackupFilename::fromStem('cpalius-db-20260909-141500.sql.gz');
    }

    public function testCollectorIncludesVendorAndPublicOmitsSecretsAndCache(): void
    {
        $collector = new BackupFileCollector($this->projectDir);

        self::assertFalse($collector->shouldInclude('.env'));
        self::assertFalse($collector->shouldInclude('.env.local'));
        self::assertFalse($collector->shouldInclude('public/page-cache/index.html'));
        self::assertFalse($collector->shouldInclude('cp-core/var/cache/dev/app.php'));
        self::assertFalse($collector->shouldInclude('.cache/opcache.bin'));
        self::assertFalse($collector->shouldInclude('cp-core/var/backups/nested.zip'));
        self::assertTrue($collector->shouldInclude('cp-includes/vendor/pkg/skip.php'));
        self::assertTrue($collector->shouldInclude('cp-content/modules/keep.txt'));
        self::assertTrue($collector->shouldInclude('public/uploads/photo.txt'));
        self::assertTrue($collector->shouldInclude('public/assets/app.css'));
        self::assertTrue($collector->shouldInclude('public/index.php'));
        self::assertTrue($collector->shouldInclude('composer.json'));

        $relative = array_column($collector->collect(), 'relative');
        self::assertContains('composer.json', $relative);
        self::assertContains('cp-content/modules/keep.txt', $relative);
        self::assertContains('cp-includes/vendor/pkg/skip.php', $relative);
        self::assertContains('public/assets/app.css', $relative);
        self::assertContains('public/index.php', $relative);
        self::assertNotContains('.env', $relative);
        self::assertNotContains('public/page-cache/index.html', $relative);
        self::assertNotContains('cp-core/var/cache/dev/app.php', $relative);
        self::assertNotContains('cp-core/var/backups/nested.zip', $relative);
    }

    public function testCollectorSkipsUnreadableDirectoriesWithoutThrowing(): void
    {
        mkdir($this->projectDir.'/.cache/hosting', 0775, true);
        file_put_contents($this->projectDir.'/.cache/hosting/x.bin', 'no');

        $locked = $this->projectDir.'/locked-dir';
        mkdir($locked, 0775, true);
        file_put_contents($locked.'/secret.txt', 'no');

        if (\DIRECTORY_SEPARATOR === '/') {
            chmod($locked, 0000);
        }

        try {
            $relative = array_column((new BackupFileCollector($this->projectDir))->collect(), 'relative');
        } finally {
            if (\DIRECTORY_SEPARATOR === '/' && is_dir($locked)) {
                chmod($locked, 0775);
            }
        }

        self::assertContains('composer.json', $relative);
        self::assertNotContains('.cache/hosting/x.bin', $relative);
        if (\DIRECTORY_SEPARATOR === '/') {
            self::assertNotContains('locked-dir/secret.txt', $relative);
        }
    }

    public function testCreatesSqliteDumpAndRejectsTraversalOnResolve(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for the dump test.');
        }

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement('CREATE TABLE demo_items (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement("INSERT INTO demo_items (id, name) VALUES (1, 'O''Brien')");

        $service = new BackupService($connection, $this->projectDir);
        $archive = $service->create(BackupFilename::TYPE_DB);

        self::assertSame(BackupFilename::TYPE_DB, $archive->type);
        self::assertTrue(BackupFilename::isValid($archive->filename));
        $path = $service->absolutePath($archive->filename);
        self::assertFileExists($path);

        $sql = gzdecode((string) file_get_contents($path));
        self::assertIsString($sql);
        self::assertStringContainsString('demo_items', $sql);
        self::assertStringContainsString('O', $sql);

        $this->expectException(BackupException::class);
        $service->absolutePath('../secrets.sql.gz');
    }

    public function testFilesArchiveIncludesVendorAndPublicOmitsEnv(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('zip extension is required for the files archive test.');
        }
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required to construct BackupService.');
        }

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $service = new BackupService($connection, $this->projectDir);
        $archive = $service->create(BackupFilename::TYPE_FILES);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($service->absolutePath($archive->filename)));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('composer.json', $names);
        self::assertContains('cp-content/modules/keep.txt', $names);
        self::assertContains('public/uploads/photo.txt', $names);
        self::assertContains('public/assets/app.css', $names);
        self::assertContains('public/index.php', $names);
        self::assertContains('cp-includes/vendor/pkg/skip.php', $names);
        self::assertNotContains('.env', $names);
        self::assertNotContains('public/page-cache/index.html', $names);
        self::assertNotContains('cp-core/var/cache/dev/app.php', $names);

        $service->delete($archive->filename);
        self::assertSame([], $service->list());
    }
}
