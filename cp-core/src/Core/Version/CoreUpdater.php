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
        // Checked before everything else, including "are you already current":
        // an interrupted update leaves a tree that is neither version, so the
        // running version cannot be trusted to answer that question. Starting a
        // second update on top would overwrite the backup that is the only way
        // back.
        if ($this->interrupted() !== null) {
            return ['aacp.version.blocker.interrupted'];
        }

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

        // Writing several thousand files is not a 30-second job, and 30 seconds
        // is what shared hosting gives by default.
        //
        // This is not a nicety, it is the whole reason 1.1.0 could break a site:
        // without it PHP hit max_execution_time part-way through install(), and
        // a time-limit fatal is NOT catchable — so the rollback in the catch
        // block below never ran, the cache clear after it never ran, and the
        // installation was left with some new files, some old files, and a
        // compiled container describing neither.
        //
        // ignore_user_abort matters just as much: many hosts put a 60-second
        // gateway timeout in front of PHP. The browser gets a 504, but the
        // process keeps running and finishes the job.
        @set_time_limit(0);
        ignore_user_abort(true);

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

        // From here until the marker is cleared, this installation is in a state
        // nobody should have to guess about. The marker records everything a
        // later request needs to finish the job or undo it: which version, where
        // staging is, where the backup is, and which files were in scope.
        //
        // It is written BEFORE the first file is overwritten, because the whole
        // point is to survive the case where the process does not come back.
        $this->writeMarker($version, $root, $backup, $files);

        // Runs even on a fatal — including the time-limit fatal that no catch
        // block can see. If the marker is still there when this fires, the
        // install did not finish, and the compiled container now describes a
        // tree that no longer exists. Clearing it is what keeps the site
        // bootable enough to show the recovery screen.
        $selfCache = $this->cache;
        $markerPath = $this->markerPath();
        register_shutdown_function(static function () use ($selfCache, $markerPath): void {
            if (!is_file($markerPath)) {
                return;
            }

            try {
                $selfCache->clearSymfonyCache();
            } catch (\Throwable) {
                // Nothing left to try; the recovery screen tells the operator
                // to empty cp-core/var/cache/<env> by hand.
            }
        });

        try {
            $written = $this->install($root, $files);
            $log[] = \sprintf('%d files written.', $written);
        } catch (\Throwable $e) {
            $this->restore($backup);
            $this->clearMarker();

            throw new \RuntimeException(
                'Install failed and the previous files were restored: '.$e->getMessage(),
                0,
                $e,
            );
        }

        // Only now is the tree consistent again.
        $this->clearMarker();

        // Cache is deliberately NOT cleared here — see finishCacheRebuild()'s
        // docblock for why purging the compiled container inside this same
        // method, before the caller has run migrations, was itself the bug.

        // Staging and the archive are large and now worthless. The backup stays:
        // it is the only copy of the previous version, and an operator who finds
        // a regression an hour later will want it.
        $this->fs->remove([$archive, $staging]);
        $log[] = 'Temporary files removed; backup kept at cp-core/var/update.';

        return $log;
    }

    /**
     * Purges and rebuilds the compiled container — call this AFTER running
     * migrations against the files apply() just wrote, never before.
     *
     * This used to run inside apply() itself, right after installing files.
     * That was the actual cause of a crash that 2.2.11 through 2.2.14 each
     * tried to fix as if it were somewhere else: AACPUpdateController runs
     * migrateOnly() in the SAME request, immediately after apply() returns,
     * using services (the Doctrine migrations DependencyFactory, and
     * whatever it lazily resolves — an event-listener proxy included) that
     * were already resolved from the container THIS request booted with,
     * before any file was touched. Purging that container mid-request, as
     * apply() used to, does not just leave a gap for some LATER request to
     * fall into — it pulls the rug out from under lazily-loaded services
     * this SAME request's own migration step is about to need, because the
     * directory their factory files live in was just renamed away. No
     * amount of rebuilding it faster afterward (2.2.12's dedicated
     * subprocess, 2.2.14's guarantee that every purge rebuilds) fixes that:
     * the new container isn't the one the already-running request is bound
     * to. The old one has to survive until migrations are done using it.
     *
     * The comment this replaced said exactly this about migrations —
     * "the one step that survives the window" — while apply() (which the
     * caller always runs first) was itself closing that window before
     * migrations ever got a turn.
     *
     * @return string
     */
    public function finishCacheRebuild(): string
    {
        return $this->clearCacheReporting();
    }

    /**
     * Details of an update that started and never reported back, or null when
     * the installation is in a known-good state.
     *
     * This is the question 1.1.0 could not answer. A half-written tree is not
     * something an operator can diagnose from the outside — the symptom is a
     * 500 mentioning a constructor signature, which says nothing about updates
     * at all — so the updater has to leave a note saying what it was doing.
     *
     * @return array{version: string, staging: string, backup: string, files: int, started_at: string, resumable: bool, restorable: bool}|null
     */
    public function interrupted(): ?array
    {
        $path = $this->markerPath();

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!\is_array($data) || !\is_string($data['version'] ?? null)) {
            // A marker we cannot read still means "something was going on", and
            // saying so with empty details beats saying nothing.
            return [
                'version' => '?',
                'staging' => '',
                'backup' => '',
                'files' => 0,
                'started_at' => '',
                'resumable' => false,
                'restorable' => false,
            ];
        }

        $staging = \is_string($data['staging'] ?? null) ? $data['staging'] : '';
        $backup = \is_string($data['backup'] ?? null) ? $data['backup'] : '';

        return [
            'version' => $data['version'],
            'staging' => $staging,
            'backup' => $backup,
            'files' => (int) ($data['files'] ?? 0),
            'started_at' => \is_string($data['started_at'] ?? null) ? $data['started_at'] : '',
            // Resume needs the extracted tree; it survives because staging is
            // only deleted on success.
            'resumable' => $staging !== '' && is_dir($staging),
            'restorable' => $backup !== '' && is_file($backup.'/.manifest.json'),
        ];
    }

    /**
     * Finishes an interrupted update by copying the staged files again.
     *
     * Re-copying every file rather than trying to work out which ones already
     * landed: copy is idempotent, and a resume that guessed wrong would leave
     * exactly the inconsistency it was called to fix. The cost is doing some
     * work twice; the alternative costs correctness.
     *
     * @return list<string>
     */
    public function resume(): array
    {
        $state = $this->interrupted();

        if ($state === null) {
            throw new \RuntimeException('There is no interrupted update to resume.');
        }

        if (!$state['resumable']) {
            throw new \RuntimeException(
                'The staged files for '.$state['version'].' are gone, so this update cannot be resumed. '
                .'Roll back, or upload the release over FTP.',
            );
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $root = $state['staging'];
        $files = $this->collectFiles($root);

        $log = [\sprintf('Resuming update to %s (%d files).', $state['version'], \count($files))];

        try {
            $written = $this->install($root, $files);
            $log[] = \sprintf('%d files written.', $written);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Resume failed; the marker is kept so you can try again or roll back: '.$e->getMessage(), 0, $e);
        }

        $this->clearMarker();

        // Cache is deliberately not cleared here — same reasoning as apply();
        // see finishCacheRebuild()'s docblock. The controller calls it after
        // this, once nothing else in the request still needs the old
        // container (recover() runs a dry-run status check right after
        // resume() returns, which is exactly the kind of lazy service
        // resolution that must not have its container pulled out from
        // under it mid-request).

        $this->fs->remove([$root, \dirname($root).'/download-'.$state['version'].'.zip']);
        $log[] = 'Temporary files removed; backup kept at cp-core/var/update.';

        return $log;
    }

    /**
     * Puts the previous version's files back.
     *
     * @return list<string>
     */
    public function rollback(): array
    {
        $state = $this->interrupted();

        if ($state === null) {
            throw new \RuntimeException('There is no interrupted update to roll back.');
        }

        if (!$state['restorable']) {
            throw new \RuntimeException(
                'No usable backup was found for '.$state['version'].'. '
                .'The previous files cannot be restored automatically; upload a known-good release over FTP.',
            );
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $this->restore($state['backup']);
        $this->clearMarker();

        // Cache is deliberately not cleared here — see finishCacheRebuild()'s
        // docblock and resume()'s comment above; same reasoning applies.
        $log = [\sprintf('Restored the files that were in place before %s.', $state['version'])];

        return $log;
    }

    /**
     * Clearing the cache is best-effort everywhere it is called from, and the
     * sentence an operator reads differs between success and failure, so the
     * whole thing lives here rather than being copied three times.
     */
    private function clearCacheReporting(): string
    {
        return implode(' ', $this->cache->afterCodeUpdate());
    }

    private function markerPath(): string
    {
        return $this->projectDir.'/cp-core/var/update/.in-progress.json';
    }

    /**
     * @param list<string> $files
     */
    private function writeMarker(string $version, string $staging, string $backup, array $files): void
    {
        $this->fs->dumpFile($this->markerPath(), json_encode([
            'version' => $version,
            'staging' => $staging,
            'backup' => $backup,
            'files' => \count($files),
            'started_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR));
    }

    private function clearMarker(): void
    {
        $this->fs->remove($this->markerPath());
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
