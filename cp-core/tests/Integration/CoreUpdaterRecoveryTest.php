<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Cache\CacheRebuildManager;
use App\Core\Version\CoreUpdater;
use App\Core\Version\ReleaseChecker;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Recovery from an update that started and never came back.
 *
 * This exists because 1.1.0 shipped without it and a live site paid for that.
 * The failure was not exotic: CoreUpdater did not lift PHP's time limit, so
 * writing several thousand files hit max_execution_time part-way through. A
 * time-limit fatal is not catchable, so the rollback in the catch block never
 * ran and neither did the cache clear — leaving new files, old files, and a
 * compiled container describing neither. The visible symptom was a 500 about a
 * constructor signature, which tells an operator nothing about updates at all.
 *
 * So the contract these tests hold is: the updater leaves a note saying what it
 * was doing, refuses to start a second update on top of the mess, and offers
 * both ways out.
 */
#[CoversClass(CoreUpdater::class)]
final class CoreUpdaterRecoveryTest extends IntegrationTestCase
{
    private const TOUCHED = 'cp-content/modules/Blog/Service/BlogCommentService.php';

    private string $projectDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/cpalius-recover-'.bin2hex(random_bytes(4));

        // The live tree, as it looked before the update started.
        $this->fs->dumpFile($this->projectDir.'/'.self::TOUCHED, "<?php // OLD\n");
        $this->fs->dumpFile($this->projectDir.'/composer.json', '{"name":"cpalius/test"}');
        $this->fs->mkdir($this->projectDir.'/cp-core/var/update');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);

        parent::tearDown();
    }

    public function testNoMarkerMeansNothingToRecover(): void
    {
        self::assertNull($this->updater()->interrupted());
    }

    public function testAnInterruptedUpdateIsReportedWithBothWaysOut(): void
    {
        $this->simulateInterruptedUpdate();

        $state = $this->updater()->interrupted();

        self::assertNotNull($state);
        self::assertSame('9.9.9', $state['version']);
        self::assertTrue($state['resumable'], 'staging survives, so the update can be finished');
        self::assertTrue($state['restorable'], 'the backup survives, so it can be undone');
    }

    /**
     * Starting a second update on top would overwrite the backup, which is the
     * only way back. So the button is withdrawn and the reason is named.
     */
    public function testAnInterruptedUpdateBlocksStartingAnotherOne(): void
    {
        $this->simulateInterruptedUpdate();

        self::assertSame(['aacp.version.blocker.interrupted'], $this->updater()->blockers());
    }

    public function testResumeFinishesTheInstallAndClearsTheMarker(): void
    {
        $this->simulateInterruptedUpdate();

        $log = $this->updater()->resume();

        self::assertSame("<?php // NEW\n", file_get_contents($this->projectDir.'/'.self::TOUCHED));
        self::assertNull($this->updater()->interrupted(), 'the marker is gone once the tree is consistent');
        self::assertNotEmpty($log);

        // Staging is large and worthless once applied; the backup stays, because
        // an operator who finds a regression an hour later will want it.
        self::assertDirectoryDoesNotExist($this->projectDir.'/cp-core/var/update/staging-9.9.9');
        self::assertFileExists($this->projectDir.'/cp-core/var/update/backup-9.9.9/.manifest.json');
    }

    public function testRollbackPutsThePreviousFilesBack(): void
    {
        $this->simulateInterruptedUpdate();

        // Simulate the half-written state: this file already got the new body
        // before the process died.
        $this->fs->dumpFile($this->projectDir.'/'.self::TOUCHED, "<?php // NEW\n");

        $this->updater()->rollback();

        self::assertSame("<?php // OLD\n", file_get_contents($this->projectDir.'/'.self::TOUCHED));
        self::assertNull($this->updater()->interrupted());
    }

    /**
     * A file the release ADDED has no previous version, so undoing the update
     * means removing it rather than restoring it.
     */
    public function testRollbackRemovesFilesTheReleaseAdded(): void
    {
        $added = 'cp-content/modules/Blog/Service/BrandNew.php';

        $this->simulateInterruptedUpdate([$added]);
        $this->fs->dumpFile($this->projectDir.'/'.$added, "<?php // added by 9.9.9\n");

        $this->updater()->rollback();

        self::assertFileDoesNotExist($this->projectDir.'/'.$added);
    }

    public function testResumeWithoutStagingRefusesRatherThanGuessing(): void
    {
        $this->simulateInterruptedUpdate();
        $this->fs->remove($this->projectDir.'/cp-core/var/update/staging-9.9.9');

        $state = $this->updater()->interrupted();
        self::assertNotNull($state);
        self::assertFalse($state['resumable']);

        $this->expectExceptionMessageMatches('/cannot be resumed/');
        $this->updater()->resume();
    }

    public function testAnUnreadableMarkerStillReportsThatSomethingHappened(): void
    {
        // Saying "an update was in progress, details unknown" beats saying
        // nothing at all on a site that is visibly broken.
        $this->fs->dumpFile($this->projectDir.'/cp-core/var/update/.in-progress.json', 'not json');

        $state = $this->updater()->interrupted();

        self::assertNotNull($state);
        self::assertFalse($state['resumable']);
        self::assertFalse($state['restorable']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Writes the on-disk state a killed install leaves behind: a marker, an
     * extracted staging tree, and a backup of what was about to be overwritten.
     *
     * @param list<string> $extraStagedFiles files the release adds
     */
    private function simulateInterruptedUpdate(array $extraStagedFiles = []): void
    {
        $work = $this->projectDir.'/cp-core/var/update';
        $staging = $work.'/staging-9.9.9';
        $backup = $work.'/backup-9.9.9';

        // Staging must look like a release, because resume() re-walks it.
        $this->fs->dumpFile($staging.'/composer.json', '{"name":"cpalius/test"}');
        $this->fs->dumpFile($staging.'/'.self::TOUCHED, "<?php // NEW\n");

        $manifest = [self::TOUCHED, 'composer.json'];

        foreach ($extraStagedFiles as $path) {
            $this->fs->dumpFile($staging.'/'.$path, "<?php // added by 9.9.9\n");
            $manifest[] = $path;
        }

        // Only files that existed are copied into the backup; the manifest
        // lists everything in scope so restore() can tell "new" from "missing".
        $this->fs->copy($this->projectDir.'/'.self::TOUCHED, $backup.'/'.self::TOUCHED, true);
        $this->fs->copy($this->projectDir.'/composer.json', $backup.'/composer.json', true);
        $this->fs->dumpFile($backup.'/.manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->fs->dumpFile($work.'/.in-progress.json', json_encode([
            'version' => '9.9.9',
            'staging' => $staging,
            'backup' => $backup,
            'files' => count($manifest),
            'started_at' => '2026-09-14T19:40:00+00:00',
        ], JSON_THROW_ON_ERROR));
    }

    private function updater(): CoreUpdater
    {
        $container = $this->container();

        /** @var ReleaseChecker $releases */
        $releases = $container->get(ReleaseChecker::class);
        /** @var CacheRebuildManager $cache */
        $cache = $container->get(CacheRebuildManager::class);

        return new CoreUpdater($this->projectDir, $releases, $cache);
    }
}
