<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Media;

use App\Core\Localization\LocaleProvider;
use App\Core\Media\MediaOffloader;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Core\Storage\StorageTargetRegistry;
use App\Entity\Setting;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use App\Tests\Unit\Core\Storage\FakeTarget;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Offload must never be able to break an upload, and the sweep must eventually
 * reach every file. Those two properties are what these tests are about.
 */
#[CoversClass(MediaOffloader::class)]
final class MediaOffloaderTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;
    private Setting $cursor;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/cpalius-offload-'.bin2hex(random_bytes(4));

        foreach (['2026/01/a.jpg', '2026/02/b.png', '2026/03/c.webp', 'cache/100x100-crop/2026/01/a.jpg'] as $key) {
            $this->fs->dumpFile($this->projectDir.'/public/uploads/'.$key, 'body-of-'.$key);
        }

        // Server-side hardening and a directory keeper: both belong to this
        // machine, and shipping .htaccess to a bucket would publish the rules
        // while protecting nothing.
        $this->fs->dumpFile($this->projectDir.'/public/uploads/.htaccess', 'deny from all');
        $this->fs->dumpFile($this->projectDir.'/public/uploads/.gitkeep', '');

        $this->cursor = new Setting('storage.media.sweep_cursor', 'core');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);
    }

    private function offloader(FakeTarget $target, int $batch = 200): MediaOffloader
    {
        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->method('findAllAsMap')->willReturn(['storage.media.sweep_batch' => (string) $batch]);
        $settingRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?Setting => ($criteria['settingKey'] ?? '') === 'storage.media.sweep_cursor'
                ? $this->cursor
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
        $registry->addDefinition(new SettingDefinition('storage.media.sweep_batch', 'batch', 'integer', 200, [], 'storage', 'media'));

        return new MediaOffloader(
            $target,
            $registry,
            $settingRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->projectDir,
        );
    }

    public function testOffloadsASingleKey(): void
    {
        $target = new FakeTarget();

        self::assertTrue($this->offloader($target)->offload('2026/01/a.jpg'));
        self::assertSame('body-of-2026/01/a.jpg', $target->objects['2026/01/a.jpg']);
    }

    public function testAcceptsAKeyWrittenAsAnUploadsPath(): void
    {
        $target = new FakeTarget();

        self::assertTrue($this->offloader($target)->offload('uploads/2026/01/a.jpg'));
        self::assertArrayHasKey('2026/01/a.jpg', $target->objects);
    }

    /**
     * The property that keeps an S3 outage from becoming a 500 on a form the
     * visitor already submitted. AssetManager calls this after the asset row is
     * flushed and ignores the result on purpose.
     */
    public function testAFailedPushIsReportedButNeverThrows(): void
    {
        $target = new FakeTarget();
        $target->failPut = true;

        self::assertFalse($this->offloader($target)->offload('2026/01/a.jpg'));
    }

    public function testDoesNothingWithoutAVerifiedTarget(): void
    {
        $target = new FakeTarget(selected: 'off');
        $offloader = $this->offloader($target);

        self::assertFalse($offloader->isEnabled());
        self::assertFalse($offloader->offload('2026/01/a.jpg'));
        self::assertSame([], $target->puts);
    }

    public function testRefusesTraversalKeys(): void
    {
        $target = new FakeTarget();

        self::assertFalse($this->offloader($target)->offload('../../.env'));
        self::assertSame([], $target->puts);
    }

    public function testSweepUploadsEverythingAndSkipsDotFiles(): void
    {
        $target = new FakeTarget();

        $report = $this->offloader($target)->sweep();

        self::assertSame(4, $report['scanned']);
        self::assertSame(4, $report['uploaded']);
        self::assertTrue($report['wrapped'], 'reaching the end resets the cursor');

        self::assertSame(
            ['2026/01/a.jpg', '2026/02/b.png', '2026/03/c.webp', 'cache/100x100-crop/2026/01/a.jpg'],
            array_keys($target->objects),
        );

        // Thumbnails are swept rather than pushed during a page render, which
        // is where a blocking upload has no business being.
        self::assertArrayHasKey('cache/100x100-crop/2026/01/a.jpg', $target->objects);

        self::assertArrayNotHasKey('.htaccess', $target->objects);
        self::assertArrayNotHasKey('.gitkeep', $target->objects);
    }

    public function testSweepSkipsWhatIsAlreadyThere(): void
    {
        $target = new FakeTarget();
        $target->objects['2026/01/a.jpg'] = 'already';

        $report = $this->offloader($target)->sweep();

        self::assertSame(1, $report['skipped']);
        self::assertSame(3, $report['uploaded']);
        self::assertSame('already', $target->objects['2026/01/a.jpg'], 'an existing object is left alone');
    }

    /**
     * The cursor is the whole point of the sweep design. Without it, a site
     * with tens of thousands of files would re-check the same first batch every
     * night and never reach the rest.
     */
    public function testSweepResumesFromTheCursorAndEventuallyCoversEverything(): void
    {
        $target = new FakeTarget();

        $first = $this->offloader($target, batch: 2)->sweep();

        self::assertSame(2, $first['scanned']);
        self::assertFalse($first['wrapped']);
        self::assertSame('2026/02/b.png', $this->cursor->getSettingValue(), 'the cursor stops on the last file handled');

        $second = $this->offloader($target, batch: 2)->sweep();

        self::assertSame(2, $second['scanned']);
        self::assertTrue($second['wrapped']);
        self::assertSame('', $this->cursor->getSettingValue(), 'wrapping resets so repaired files are reconsidered');

        self::assertCount(4, $target->objects, 'two bounded runs covered the whole tree');
    }

    public function testSweepCountsFailuresWithoutStopping(): void
    {
        $target = new FakeTarget();
        $target->failPut = true;

        $report = $this->offloader($target)->sweep();

        self::assertSame(4, $report['scanned']);
        self::assertSame(4, $report['failed']);
        self::assertSame(0, $report['uploaded']);
        self::assertTrue($report['wrapped'], 'a failing target must still not stall the cursor forever');
    }

    public function testSweepDoesNothingWithoutATarget(): void
    {
        $target = new FakeTarget(selected: 'off');

        $report = $this->offloader($target)->sweep();

        self::assertSame(0, $report['scanned']);
        self::assertSame([], $target->puts);
    }

    public function testForgetRemovesTheRemoteCopy(): void
    {
        $target = new FakeTarget();
        $offloader = $this->offloader($target);

        $offloader->offload('2026/01/a.jpg');
        $offloader->forget('2026/01/a.jpg');

        self::assertSame([], $target->objects);
    }

    public function testForgetSwallowsRemoteFailures(): void
    {
        $target = new FakeTarget();
        $target->failDelete = true;

        // Deleting an asset must not 500 because a bucket is unreachable; an
        // orphaned object costs pennies.
        $this->offloader($target)->forget('2026/01/a.jpg');

        $this->addToAssertionCount(1);
    }

}
