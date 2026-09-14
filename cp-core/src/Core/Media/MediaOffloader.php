<?php

declare(strict_types=1);

namespace App\Core\Media;

use App\Core\Storage\StorageTargetRegistry;
use App\Core\Storage\TargetResolverInterface;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use App\Core\Settings\SettingsRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Copies files from public/uploads to a remote target.
 *
 * "Copies", not "moves". The local file stays, and stays canonical:
 *
 *   - ImageProcessor resizes with GD, which reads files off a disk. A remote
 *     original would mean downloading the source on every cache miss, turning
 *     a thumbnail into a network round trip inside a page render.
 *   - public/uploads/.htaccess is five layers of hardening (SEC-01) that stops
 *     an uploaded file from ever being executed. None of it follows the bytes
 *     into a bucket; there, protection is a bucket policy the operator owns.
 *   - The files backup would otherwise stop containing the site's media, and
 *     nobody discovers that until a restore.
 *
 * So the remote copy is a delivery optimisation, not a relocation, and every
 * failure here is survivable by construction: the origin can still serve the
 * file. That is why offload() returns a bool and never throws — an S3 outage
 * must not turn a working image upload into a 500.
 */
final class MediaOffloader
{
    private const CURSOR_KEY = 'storage.media.sweep_cursor';

    /** Never sweep more than this in one run, whatever the setting says. */
    private const MAX_BATCH = 2000;

    private readonly string $uploadsDir;

    public function __construct(
        private readonly TargetResolverInterface $targets,
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->uploadsDir = rtrim(str_replace('\\', '/', $projectDir), '/').'/public/uploads';
    }

    public function isEnabled(): bool
    {
        return $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_MEDIA) !== null;
    }

    /**
     * Pushes one storage key. Returns false when offload is off or the copy
     * failed; the caller carries on either way.
     */
    public function offload(string $storageKey): bool
    {
        $target = $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_MEDIA);

        if ($target === null) {
            return false;
        }

        $key = $this->normalizeKey($storageKey);
        $local = $this->uploadsDir.'/'.$key;

        if ($key === '' || !is_file($local)) {
            return false;
        }

        try {
            $target->put($local, $key);

            return true;
        } catch (\Throwable $e) {
            // Warning, not error: the site is serving the file correctly from
            // the origin. This is a degraded optimisation, not an incident.
            $this->logger?->warning('Media offload failed for {key}: {reason}', [
                'key' => $key,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Removes the remote copy. Best-effort, for the same reason as offload():
     * an orphaned object costs pennies, a 500 on asset deletion costs a page.
     */
    public function forget(string $storageKey): void
    {
        $target = $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_MEDIA);
        $key = $this->normalizeKey($storageKey);

        if ($target === null || $key === '') {
            return;
        }

        try {
            $target->delete($key);
        } catch (\Throwable $e) {
            $this->logger?->warning('Remote media delete failed for {key}: {reason}', [
                'key' => $key,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Walks uploads in sorted order from a stored cursor and pushes what is
     * missing, up to $limit files.
     *
     * The cursor is the whole design. A site that turns offload on after two
     * years has tens of thousands of files, and a sweep that started from the
     * beginning every night would re-check the same first few hundred forever
     * and never reach the rest. Walking from where the last run stopped, and
     * wrapping to the start on completion, gets through the backlog in
     * bounded steps and then keeps catching anything the synchronous push at
     * upload time missed — a failed PUT during an outage, and thumbnails,
     * which are generated during a page render where a network call has no
     * business being.
     *
     * @return array{scanned: int, uploaded: int, skipped: int, failed: int, cursor: string, wrapped: bool}
     */
    public function sweep(?int $limit = null): array
    {
        $target = $this->targets->resolveFor(StorageTargetRegistry::PURPOSE_MEDIA);

        $report = ['scanned' => 0, 'uploaded' => 0, 'skipped' => 0, 'failed' => 0, 'cursor' => '', 'wrapped' => false];

        if ($target === null || !is_dir($this->uploadsDir)) {
            return $report;
        }

        $limit ??= (int) $this->settings->get('storage.media.sweep_batch', 200);
        $limit = max(1, min(self::MAX_BATCH, $limit));

        $cursor = $this->cursor();
        $keys = $this->collectKeys();

        $report['cursor'] = $cursor;

        foreach ($keys as $key) {
            if ($cursor !== '' && strcmp($key, $cursor) <= 0) {
                continue;
            }

            if ($report['scanned'] >= $limit) {
                $this->saveCursor($report['cursor']);

                return $report;
            }

            ++$report['scanned'];
            $report['cursor'] = $key;

            try {
                if ($target->has($key)) {
                    ++$report['skipped'];

                    continue;
                }

                $target->put($this->uploadsDir.'/'.$key, $key);
                ++$report['uploaded'];
            } catch (\Throwable $e) {
                ++$report['failed'];
                $this->logger?->warning('Media sweep failed for {key}: {reason}', [
                    'key' => $key,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        // Reached the end: start over next time so files added or repaired
        // behind the cursor are eventually reconsidered.
        $report['wrapped'] = true;
        $this->saveCursor('');

        return $report;
    }

    /**
     * Every eligible key under uploads, sorted so the cursor means something.
     *
     * Sorting a large tree costs memory once per sweep; comparing paths
     * without a total order would let the cursor skip files at random,
     * which is the one failure this design must not have.
     *
     * @return list<string>
     */
    private function collectKeys(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $keys = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $key = $this->normalizeKey(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->uploadsDir) + 1));

            // .htaccess and .gitkeep are this server's business. Shipping the
            // hardening file to a bucket would publish the rules and protect
            // nothing.
            if ($key === '' || str_starts_with(basename($key), '.')) {
                continue;
            }

            $keys[] = $key;
        }

        sort($keys);

        return $keys;
    }

    private function cursor(): string
    {
        $raw = $this->settingRepository->findOneBy(['settingKey' => self::CURSOR_KEY])?->getSettingValue();

        return is_string($raw) ? $raw : '';
    }

    private function saveCursor(string $cursor): void
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => self::CURSOR_KEY]);

        if (!$setting instanceof Setting) {
            $setting = new Setting(self::CURSOR_KEY, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($cursor);
        $this->entityManager->flush();
    }

    private function normalizeKey(string $source): string
    {
        $key = ltrim(str_replace('\\', '/', trim($source)), '/');

        if (str_starts_with($key, 'uploads/')) {
            $key = substr($key, strlen('uploads/'));
        }

        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            return '';
        }

        return $key;
    }
}
