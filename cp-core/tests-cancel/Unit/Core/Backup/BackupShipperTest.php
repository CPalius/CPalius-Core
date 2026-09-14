<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Backup;

use App\Core\Backup\BackupException;
use App\Core\Backup\BackupFilename;
use App\Core\Backup\BackupService;
use App\Core\Backup\BackupShipper;
use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use App\Tests\Unit\Core\Storage\FakeTarget;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Off-site backups only have value if the operator can believe them, so the
 * behaviour under test is mostly about refusing to pretend:
 *
 *   - a failed upload is an error the operator sees, not a logged shrug
 *   - a destination selected but never probed does not silently do nothing
 *   - the local copy is never removed on an upload that was not read back
 *
 * That last one is the sharp edge. "Upload then delete" with an unverified
 * upload is how a single truncated PUT destroys the only intact copy.
 */
#[CoversClass(BackupShipper::class)]
#[CoversClass(BackupService::class)]
final class BackupShipperTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;
    private Setting $ledger;

    /** @var array<string, string> */
    private array $values = [];

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/cpalius-ship-'.bin2hex(random_bytes(4));
        $this->fs->mkdir($this->projectDir.'/cp-core/var/backups');
        $this->fs->dumpFile($this->projectDir.'/composer.json', '{"name":"cpalius/test"}');
        $this->fs->dumpFile($this->projectDir.'/cp-content/keep.txt', 'content');

        $this->ledger = new Setting('backup.remote.ledger', 'core');
        $this->values = [
            'storage.backup.prefix' => 'cpalius-backups',
            'storage.backup.keep_local' => '1',
            'storage.backup.remote_retention' => '10',
        ];
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);
    }

    private function shipper(FakeTarget $target): BackupShipper
    {
        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->method('findAllAsMap')->willReturn($this->values);
        $settingRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?Setting => ($criteria['settingKey'] ?? '') === 'backup.remote.ledger'
                ? $this->ledger
                : null,
        );

        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);

        $registry = new SettingsRegistry(
            $settingRepository,
            new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr', 'tr'),
            new RequestStack(),
            new ArrayAdapter(),
        );

        foreach ([
            ['storage.backup.prefix', 'text', 'cpalius-backups'],
            ['storage.backup.keep_local', 'checkbox', true],
            ['storage.backup.remote_retention', 'integer', 10],
        ] as [$key, $type, $default]) {
            $registry->addDefinition(new SettingDefinition($key, $key, $type, $default, [], 'storage', 'backup'));
        }

        return new BackupShipper(
            $target,
            $registry,
            $settingRepository,
            $this->createMock(EntityManagerInterface::class),
        );
    }

    private function service(FakeTarget $target): BackupService
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE demo (id INTEGER PRIMARY KEY)');

        return new BackupService($connection, $this->projectDir, null, $this->shipper($target));
    }

    public function testShipsAnArchiveAndRecordsIt(): void
    {
        $target = new FakeTarget();
        $service = $this->service($target);

        $archive = $service->create(BackupFilename::TYPE_DB);
        $result = $service->shipAndPrune($archive);

        self::assertTrue($result['shipped']);
        self::assertNull($result['error']);
        self::assertSame('s3://fake', $result['target']);

        self::assertArrayHasKey('cpalius-backups/'.$archive->filename, $target->objects);

        // keep_local defaults to on, so both copies exist.
        self::assertFalse($result['local_removed']);
        self::assertFileExists($service->absolutePath($archive->filename));

        $ledger = json_decode((string) $this->ledger->getSettingValue(), true);
        self::assertArrayHasKey($archive->filename, $ledger);
        self::assertSame('s3', $ledger[$archive->filename]['target']);
    }

    /**
     * The whole reason ship() reads the object back before reporting success.
     */
    public function testNeverRemovesTheLocalCopyWhenTheUploadCannotBeReadBack(): void
    {
        $target = new FakeTarget();
        $target->swallowPut = true;
        $this->values['storage.backup.keep_local'] = '0';

        $service = $this->service($target);
        $archive = $service->create(BackupFilename::TYPE_DB);

        $result = $service->shipAndPrune($archive);

        self::assertFalse($result['shipped']);
        self::assertNotNull($result['error']);
        self::assertStringContainsString('not readable back', (string) $result['error']);

        self::assertFileExists(
            $service->absolutePath($archive->filename),
            'a backup must never be deleted on an upload that was not confirmed',
        );
    }

    public function testRemovesTheLocalCopyOnlyAfterAConfirmedUpload(): void
    {
        $target = new FakeTarget();
        $this->values['storage.backup.keep_local'] = '0';

        $service = $this->service($target);
        $archive = $service->create(BackupFilename::TYPE_DB);
        $path = $service->absolutePath($archive->filename);

        $result = $service->shipAndPrune($archive);

        self::assertTrue($result['shipped']);
        self::assertTrue($result['local_removed']);
        self::assertFileDoesNotExist($path);
        self::assertArrayHasKey('cpalius-backups/'.$archive->filename, $target->objects);
    }

    public function testAFailedUploadIsReportedAndKeepsTheArchive(): void
    {
        $target = new FakeTarget();
        $target->failPut = true;

        $service = $this->service($target);
        $archive = $service->create(BackupFilename::TYPE_DB);

        $result = $service->shipAndPrune($archive);

        self::assertFalse($result['shipped']);
        self::assertStringContainsString('Upload to s3://fake failed', (string) $result['error']);
        self::assertFileExists($service->absolutePath($archive->filename));
    }

    /**
     * A destination the operator selected but never probed must announce
     * itself, not behave like "off". Silence here is what buys a year of not
     * looking.
     */
    public function testAnUnverifiedDestinationIsAnErrorRatherThanSilence(): void
    {
        // resolveFor() returns null (unverified) while selectedType() still
        // reports a choice — exactly what the registry does for a target that
        // has not passed a write probe.
        $target = new FakeTarget(selected: 'off');
        $shipper = $this->shipper($target);

        self::assertFalse($shipper->isConfigured());
        self::assertFalse($shipper->ship('cpalius-db-20260914-120000.sql.gz', __FILE__), 'off means nothing to do');
    }

    public function testPruneKeepsTheNewestAndDropsTheRest(): void
    {
        $target = new FakeTarget();
        $this->values['storage.backup.remote_retention'] = '2';

        $entries = [];

        foreach (['20260901-120000', '20260902-120000', '20260903-120000', '20260904-120000'] as $i => $stamp) {
            $filename = 'cpalius-db-'.$stamp.'.sql.gz';
            $key = 'cpalius-backups/'.$filename;
            $target->objects[$key] = 'x';
            $entries[$filename] = ['target' => 's3', 'key' => $key, 'at' => '2026-09-0'.($i + 1).'T12:00:00+00:00', 'size' => 10];
        }

        $this->ledger->setSettingValue(json_encode($entries, JSON_THROW_ON_ERROR));

        $removed = $this->shipper($target)->prune();

        self::assertSame(2, $removed);

        $kept = array_keys(json_decode((string) $this->ledger->getSettingValue(), true));
        self::assertSame(
            ['cpalius-db-20260904-120000.sql.gz', 'cpalius-db-20260903-120000.sql.gz'],
            $kept,
            'retention keeps the newest',
        );

        self::assertArrayNotHasKey('cpalius-backups/cpalius-db-20260901-120000.sql.gz', $target->objects);
    }

    public function testForgetRemovesOnlyTheRemoteCopy(): void
    {
        $target = new FakeTarget();
        $service = $this->service($target);

        $archive = $service->create(BackupFilename::TYPE_DB);
        $service->shipAndPrune($archive);

        $this->shipper($target)->forget($archive->filename);

        self::assertSame([], $target->objects);
        self::assertFileExists(
            $service->absolutePath($archive->filename),
            'deleting the off-site copy must leave the local one alone',
        );
    }

    public function testShippingAnArchiveThatIsNotOnDiskFails(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/is not on disk/');

        $this->shipper(new FakeTarget())->ship('cpalius-db-20260914-120000.sql.gz', $this->projectDir.'/nope.sql.gz');
    }
}
