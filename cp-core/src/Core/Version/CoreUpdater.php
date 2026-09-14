<?php

declare(strict_types=1);

namespace App\Core\Version;

use App\Core\Cache\CacheRebuildManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Downloads a release archive and writes it over the running installation.
 *
 * This is the one subsystem in CPalius that can destroy the site it runs in, so
 * the order of operations is the design:
 *
 *   verify BEFORE unpacking   — a digest mismatch must never reach the disk as
 *                               code, so the archive is hashed while it is
 *                               still an opaque file
 *   unpack to STAGING         — extraction failures, truncated archives and
 *                               zip-slip paths are caught somewhere harmless
 *   validate the staging tree — an archive that is not CPalius (a stray zip, a
 *                               404 page saved as .zip) is rejected before a
 *                               single live file is touched
 *   back up, THEN overwrite   — the backup is what makes the last step
 *                               reversible; without it a half-finished copy is
 *                               a permanently broken site
 *
 * Two deliberate omissions:
 *
 * Files are copied OVER the installation, never deleted first. A delete-then-
 * copy is cleaner in theory and catastrophic in practice — if the process dies
 * between the two, the site is gone rather than merely out of date. The cost is
 * that files removed upstream linger; that is the right trade.
 *
 * User data is never in the archive's path. .env holds the database password
 * and is the one file an update must not touch, and uploads/page-cache/var hold
 * content and state that no release can know about. Which paths those are lives
 * in ProtectedPaths, shared with PatchInstaller — the two writers of remote
 * content must agree, and a rule kept in two places eventually does not.
 */
final class CoreUpdater
{
    /** Files that must exist in the staging tree for it to be CPalius at all. */
    private const SIGNATURE_FILES = [
        'composer.json',
        'public/index.php',
        'cp-core/src/Kernel.php',
    ];

    private const DOWNLOAD_TIMEOUT = 300;

    /** A release archive far outside this range is not a release archive. */
    private const MIN_ARCHIVE_BYTES = 65536;
    private const MAX_ARCHIVE_BYTES = 524288000;

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $projectDir,
        private readonly ReleaseChecker $releases,
        private readonly CacheRebuildManager $cache,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Reasons this installation cannot be updated right now, as translation
     * keys. An empty list means the button is safe to offer.
     *
     * Checked before rendering rather than on submit: an operator should see
     * why an update is unavailable, not discover it after clicking.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $status = $this->releases->status();
        $blockers = [];

        if ($status === null) {
            return ['aacp.version.blocker.no_check'];
        }

        if (!$status['outdated']) {
            return ['aacp.version.blocker.current'];
        }

        if ($status['download_zip'] === null) {
            $blockers[] = 'aacp.version.blocker.no_package';
        }

        // Refused rather than warned about: an archive that cannot be verified
        // is indistinguishable from one that has been tampered with.
        if ($status['download_sha256'] === null) {
            $blockers[] = 'aacp.version.blocker.no_checksum';
        }

        if (!class_exists(\ZipArchive::class)) {
            $blockers[] = 'aacp.version.blocker.no_zip_extension';
        }

        if (!is_writable($this->projectDir.'/cp-core')) {
            $blockers[] = 'aacp.version.blocker.not_writable';
        }

        return $blockers;
    }

    /**
     * Performs the update. Returns a human-readable log of what happened.
     *
     * Throws on any failure, having first restored whatever it had overwritten.
     * A caller that catches the exception is looking at an installation that is
     * still on the old version — which is the only acceptable failure mode.
     *
     * @return list<string>
     */
    public function apply(): array
    {
        $blockers = $this->blockers();

        if ($blockers !== []) {
            throw new \RuntimeException('Update refused: '.implode(', ', $blockers));
        }

        /** @var array{version: string, download_zip: string, download_sha256: string} $status */
        $status = $this->releases->status();

        $version = $status['version'];
        $workDir = $this->projectDir.'/cp-core/var/update';
        $archive = $workDir.'/download-'.$version.'.zip';
        $staging = $workDir.'/staging-'.$version;
        $backup = $workDir.'/backup-'.$version;

        $log = [];

        // A previous attempt's leftovers would be mistaken for this attempt's
        // work — in particular a stale backup would restore the wrong files.
        $this->fs->remove([$archive, $staging, $backup]);
        $this->fs->mkdir($workDir);

        $bytes = $this->download($status['download_zip'], $archive);
        $log[] = \sprintf('Downloaded %s (%s bytes).', basename($archive), number_format($bytes));

        $this->verifyDigest($archive, $status['download_sha256']);
        $log[] = 'Checksum verified (SHA-256).';

        $root = $this->extract($archive, $staging);
        $log[] = 'Archive extracted to staging.';

        $this->validateStaging($root);
        $log[] = 'Staging tree validated as a CPalius release.';

        $files = $this->collectFiles($root);
        $log[] = \sprintf('%d files to apply.', \count($files));

        $this->backup($files, $backup);
        $log[] = 'Existing files backed up.';

        try {
            $written = $this->install($root, $files);
            $log[] = \sprintf('%d files written.', $written);
        } catch (\Throwable $e) {
            $this->restore($backup);

            throw new \RuntimeException(
                'Install failed and the previous files were restored: '.$e->getMessage(),
                0,
                $e,
            );
        }

        // The compiled container, the Twig cache and the translation catalogues
        // on disk all describe the code that was running a second ago. In prod
        // Symfony never rebuilds them on its own, so leaving this to a second
        // click would mean every request in between runs NEW files against an
        // OLD container — which is not "slightly stale", it is a 500 on a site
        // whose admin panel is the thing that would have offered the button to
        // fix it. Clearing here closes that window.
        try {
            $this->cache->clearSymfonyCache();
            $log[] = 'Caches cleared; the next request rebuilds against the new code.';
        } catch (\Throwable $e) {
            // Not fatal, and deliberately not a rollback: the files are correct
            // and consistent, and a cache an operator can delete over FTP is a
            // far better place to be than a restored older version.
            $log[] = 'WARNING: cache could not be cleared ('.$e->getMessage()
                .'). Delete cp-core/var/cache/<env> by hand before using the site.';
        }

        // Staging and the archive are large and now worthless. The backup stays:
        // it is the only copy of the previous version, and an operator who finds
        // a regression an hour later will want it.
        $this->fs->remove([$archive, $staging]);
        $log[] = 'Temporary files removed; backup kept at cp-core/var/update.';

        return $log;
    }

    private function download(string $url, string $target): int
    {
        $client = HttpClient::create([
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'max_duration' => self::DOWNLOAD_TIMEOUT,
            'headers' => ['User-Agent' => 'CPalius/'.CpVersion::VERSION.' (+https://www.cpalius.com)'],
        ]);

        $response = $client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Download failed: HTTP '.$response->getStatusCode());
        }

        $handle = fopen($target, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot write to '.$target);
        }

        $written = 0;

        try {
            // Streamed rather than getContent(): a release archive is tens of
            // megabytes and shared hosting memory limits are not.
            foreach ($client->stream($response) as $chunk) {
                $written += fwrite($handle, $chunk->getContent()) ?: 0;

                if ($written > self::MAX_ARCHIVE_BYTES) {
                    throw new \RuntimeException('Archive exceeds the size limit');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($written < self::MIN_ARCHIVE_BYTES) {
            throw new \RuntimeException('Archive is implausibly small ('.$written.' bytes)');
        }

        return $written;
    }

    private function verifyDigest(string $archive, string $expected): void
    {
        $actual = hash_file('sha256', $archive);

        // hash_equals, not ===: digest comparison is the security boundary here,
        // and a timing-variable comparison is a bad habit to leave in one.
        if (!\is_string($actual) || !hash_equals(strtolower($expected), strtolower($actual))) {
            throw new \RuntimeException('Checksum mismatch — the archive was not what the release feed described.');
        }
    }

    /**
     * Extracts to staging and returns the directory the payload actually lives
     * in — archives are commonly wrapped in a single top-level folder.
     */
    private function extract(string $archive, string $staging): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('Archive could not be opened');
        }

        // Zip-slip: an entry named ../../public/index.php would escape staging
        // and land anywhere the process can write. Checked before extracting a
        // single byte, because extractTo() is all-or-nothing.
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name) === 1) {
                $zip->close();

                throw new \RuntimeException('Archive contains an unsafe path: '.$name);
            }
        }

        $ok = $zip->extractTo($staging);
        $zip->close();

        if ($ok !== true) {
            throw new \RuntimeException('Archive could not be extracted');
        }

        if (is_file($staging.'/composer.json')) {
            return $staging;
        }

        $entries = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));

        if (\count($entries) === 1 && is_dir($staging.'/'.$entries[0])) {
            return $staging.'/'.$entries[0];
        }

        throw new \RuntimeException('Archive layout not recognised');
    }

    private function validateStaging(string $root): void
    {
        foreach (self::SIGNATURE_FILES as $file) {
            if (!is_file($root.'/'.$file)) {
                throw new \RuntimeException('Staging tree is missing '.$file.' — this is not a CPalius release.');
            }
        }
    }

    /**
     * Archive-relative paths that will be written, protected paths removed.
     *
     * @return list<string>
     */
    private function collectFiles(string $root): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $files = [];

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr((string) $item->getPathname(), \strlen($root) + 1));

            if (ProtectedPaths::isProtected($relative)) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private function backup(array $files, string $backup): void
    {
        foreach ($files as $relative) {
            $live = $this->projectDir.'/'.$relative;

            // Only existing files are backed up. A file the release adds has no
            // previous version, and restore() deletes those instead.
            if (!is_file($live)) {
                continue;
            }

            $this->fs->copy($live, $backup.'/'.$relative, true);
        }

        // Recorded so restore() can tell "this file is new" from "this file was
        // not backed up because copying failed".
        $this->fs->dumpFile($backup.'/.manifest.json', json_encode($files, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $files
     */
    private function install(string $root, array $files): int
    {
        $written = 0;

        foreach ($files as $relative) {
            $this->fs->copy($root.'/'.$relative, $this->projectDir.'/'.$relative, true);
            ++$written;
        }

        return $written;
    }

    private function restore(string $backup): void
    {
        $manifestFile = $backup.'/.manifest.json';

        if (!is_file($manifestFile)) {
            return;
        }

        $files = json_decode((string) file_get_contents($manifestFile), true);

        if (!\is_array($files)) {
            return;
        }

        foreach ($files as $relative) {
            if (!\is_string($relative)) {
                continue;
            }

            $saved = $backup.'/'.$relative;
            $live = $this->projectDir.'/'.$relative;

            if (is_file($saved)) {
                $this->fs->copy($saved, $live, true);
            } elseif (is_file($live)) {
                // Present in the manifest but absent from the backup means the
                // release added it — undoing the update means removing it again.
                $this->fs->remove($live);
            }
        }
    }
}
