<?php

declare(strict_types=1);

namespace App\Core\Version;

use App\Core\Cache\CacheRebuildManager;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Applies a file-level patch to the running installation.
 *
 * The same job CoreUpdater does, at a hundredth of the size, and the order of
 * operations is copied from it deliberately — this is the more dangerous of the
 * two, not the less. A release archive is one artefact with one digest; a patch
 * is a list of URLs, and the list is the thing being trusted.
 *
 *   1. DOWNLOAD EVERYTHING FIRST, verifying each file's SHA-256 as it lands in
 *      staging. Nothing touches the project tree until every single file has
 *      arrived and matched. A patch that half-applies because the network died
 *      on file nine of twelve is a site running a combination of versions that
 *      was never tested and that nothing records.
 *
 *   2. PROVE THE VERSION FILE. The staged CpVersion.php must actually declare
 *      the version the manifest claims. Without this, a manifest could announce
 *      1.1.1 while shipping code that still says 1.1.0 — the patch would apply,
 *      the running version would not move, and the same patch would be offered
 *      again on every check, forever.
 *
 *   3. BACK UP, THEN WRITE. The backup is what makes step 4 possible. It is
 *      kept after success, not deleted: an operator who finds a regression an
 *      hour later wants the previous files, and they are small.
 *
 *   4. RESTORE ON ANY FAILURE. A patch that fails mid-write restores every file
 *      it touched and leaves the installation on the old version — the only
 *      acceptable failure mode.
 *
 * Applying is never automatic. There is no code path from the cron check to
 * this class; something has to POST a CSRF-protected form or type a command.
 * Auto-applying would mean whoever controls the CPalius GitHub organisation
 * controls every installation in the field without an operator in the loop, and
 * a convenience is not worth that.
 */
final class PatchInstaller
{
    private const LEDGER_KEY = 'update.core.applied_patches';

    private const DOWNLOAD_TIMEOUT = 120;

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $projectDir,
        private readonly PatchChecker $patches,
        private readonly CacheRebuildManager $cache,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Reasons a patch cannot be applied right now, as translation keys.
     *
     * Checked before the button is rendered, like CoreUpdater::blockers(): an
     * operator should see why an update is unavailable, not find out after
     * clicking.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $next = $this->patches->next();

        if ($next === null) {
            return $this->patches->all() === []
                ? ['aacp.patch.blocker.no_check']
                : ['aacp.patch.blocker.none_pending'];
        }

        $blockers = [];

        if (!is_writable($this->projectDir.'/cp-core')) {
            $blockers[] = 'aacp.version.blocker.not_writable';
        }

        return $blockers;
    }

    /**
     * Downloads and validates without writing anything, so the screen can show
     * the operator exactly which files a patch would replace before they agree
     * to it. A patch nobody can inspect is a patch nobody should install.
     *
     * @throws \RuntimeException when the manifest cannot be fetched or validated
     */
    public function plan(): ?PatchManifest
    {
        $next = $this->patches->next();

        if ($next === null) {
            return null;
        }

        $manifest = $this->patches->fetchManifest($next['manifest']);

        if (!$manifest->appliesTo(CpVersion::VERSION)) {
            throw new \RuntimeException(sprintf(
                'Patch %s upgrades from %s, but this installation runs %s.',
                $manifest->version,
                $manifest->base,
                CpVersion::VERSION,
            ));
        }

        return $manifest;
    }

    /**
     * Applies the next pending patch. Returns a human-readable log.
     *
     * @throws \RuntimeException having restored anything it had already written
     *
     * @return list<string>
     */
    public function apply(): array
    {
        $blockers = $this->blockers();

        if ($blockers !== []) {
            throw new \RuntimeException('Patch refused: '.implode(', ', $blockers));
        }

        $manifest = $this->plan();

        if ($manifest === null) {
            throw new \RuntimeException('No patch is pending.');
        }

        $workDir = $this->projectDir.'/cp-core/var/update';
        $staging = $workDir.'/patch-staging-'.$manifest->version;
        $backup = $workDir.'/patch-backup-'.$manifest->version;

        // A previous attempt's leftovers would be mistaken for this attempt's
        // work — in particular a stale backup would restore the wrong files.
        $this->fs->remove([$staging, $backup]);
        $this->fs->mkdir($workDir);

        $log = [sprintf('Patch %s (from %s): %d file(s).', $manifest->version, $manifest->base, count($manifest->files))];

        try {
            $bytes = $this->download($manifest, $staging);
            $log[] = sprintf('Downloaded and verified %s bytes.', number_format($bytes));

            $this->assertVersionFile($manifest, $staging);
            $log[] = sprintf('Staged CpVersion.php declares %s.', $manifest->version);

            $this->backup($manifest, $backup);
            $log[] = 'Existing files backed up.';
        } catch (\Throwable $e) {
            $this->fs->remove($staging);

            throw new \RuntimeException('Patch refused before any file was changed: '.$e->getMessage(), 0, $e);
        }

        try {
            [$written, $deleted] = $this->install($manifest, $staging);
            $log[] = sprintf('%d file(s) written, %d removed.', $written, $deleted);
        } catch (\Throwable $e) {
            $this->restore($backup);

            throw new \RuntimeException(
                'Patch failed and the previous files were restored: '.$e->getMessage(),
                0,
                $e,
            );
        }

        // The compiled container and the Twig cache on disk describe the code
        // that was running a second ago. In prod Symfony never rebuilds them on
        // its own, so leaving this to a second click would mean every request in
        // between runs NEW files against an OLD container.
        try {
            $this->cache->clearSymfonyCache();
            $log[] = 'Caches cleared; the next request rebuilds against the new code.';
        } catch (\Throwable $e) {
            // Not fatal and deliberately not a rollback: the files are correct
            // and consistent, and a cache an operator can delete over FTP is a
            // far better place to be than a restored older version.
            $log[] = 'WARNING: cache could not be cleared ('.$e->getMessage()
                .'). Delete cp-core/var/cache/<env> by hand before using the site.';
        }

        $this->record($manifest);

        // Staging is worthless now. The backup stays: it is the only copy of
        // the previous files.
        $this->fs->remove($staging);
        $log[] = sprintf('Done. Previous files kept at cp-core/var/update/patch-backup-%s.', $manifest->version);

        return $log;
    }

    /**
     * version => applied_at, for the screen.
     *
     * @return array<string, string>
     */
    public function appliedLedger(): array
    {
        $raw = $this->settings->findOneBy(['settingKey' => self::LEDGER_KEY])?->getSettingValue();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $ledger = [];

        foreach ($decoded as $version => $at) {
            if (is_string($version) && is_string($at)) {
                $ledger[$version] = $at;
            }
        }

        return $ledger;
    }

    /**
     * Fetches every write entry into staging, verifying digests as it goes.
     *
     * @return int total bytes written to staging
     */
    private function download(PatchManifest $manifest, string $staging): int
    {
        $client = $this->httpClient ?? HttpClient::create([
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'max_duration' => self::DOWNLOAD_TIMEOUT,
            'headers' => ['User-Agent' => 'CPalius/'.CpVersion::VERSION.' (+https://www.cpalius.com)'],
        ]);

        $total = 0;

        foreach ($manifest->writes() as $entry) {
            $url = $manifest->urlFor($entry['path']);
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('%s: HTTP %d', $entry['path'], $response->getStatusCode()));
            }

            $body = $response->getContent(false);

            if (strlen($body) > PatchManifest::MAX_FILE_BYTES) {
                throw new \RuntimeException(sprintf('%s is larger than a patch file may be.', $entry['path']));
            }

            // hash_equals rather than ===: this comparison is the security
            // boundary of the whole feature, and a timing-variable comparison
            // is a bad habit to leave in one.
            $actual = hash('sha256', $body);

            if (!hash_equals((string) $entry['sha256'], $actual)) {
                throw new \RuntimeException(sprintf('%s does not match its declared SHA-256.', $entry['path']));
            }

            $this->fs->dumpFile($staging.'/'.$entry['path'], $body);
            $total += strlen($body);
        }

        return $total;
    }

    /**
     * Refuses a patch whose staged version constant disagrees with its manifest.
     *
     * Read out of the staged file with a regex rather than by including it: this
     * file has not been trusted yet, and including PHP to find out whether it is
     * safe to install has the order of operations exactly backwards.
     */
    private function assertVersionFile(PatchManifest $manifest, string $staging): void
    {
        $path = $staging.'/'.PatchManifest::VERSION_FILE;
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", $contents, $matches) !== 1) {
            throw new \RuntimeException('The staged CpVersion.php has no readable VERSION constant.');
        }

        if ($matches[1] !== $manifest->version) {
            throw new \RuntimeException(sprintf(
                'The staged CpVersion.php declares %s but the patch claims %s.',
                $matches[1],
                $manifest->version,
            ));
        }
    }

    private function backup(PatchManifest $manifest, string $backup): void
    {
        $paths = [];

        foreach ($manifest->files as $entry) {
            $paths[] = $entry['path'];
            $live = $this->projectDir.'/'.$entry['path'];

            // Only existing files are copied. A file the patch adds has no
            // previous version, and restore() deletes those instead.
            if (is_file($live)) {
                $this->fs->copy($live, $backup.'/'.$entry['path'], true);
            }
        }

        // Recorded so restore() can tell "this file is new" from "this file was
        // not backed up because copying failed".
        $this->fs->dumpFile($backup.'/.manifest.json', json_encode($paths, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: int, 1: int} written, deleted
     */
    private function install(PatchManifest $manifest, string $staging): array
    {
        $written = 0;
        $deleted = 0;

        foreach ($manifest->writes() as $entry) {
            $this->fs->copy($staging.'/'.$entry['path'], $this->projectDir.'/'.$entry['path'], true);
            ++$written;
        }

        // Deletions run last. A file removed before its replacement was written
        // is a window in which the site is missing a class it still references;
        // doing it in this order means the only window is between two correct
        // states.
        foreach ($manifest->deletions() as $path) {
            $live = $this->projectDir.'/'.$path;

            if (is_file($live)) {
                $this->fs->remove($live);
                ++$deleted;
            }
        }

        return [$written, $deleted];
    }

    private function restore(string $backup): void
    {
        $manifestFile = $backup.'/.manifest.json';

        if (!is_file($manifestFile)) {
            return;
        }

        $paths = json_decode((string) file_get_contents($manifestFile), true);

        if (!is_array($paths)) {
            return;
        }

        foreach ($paths as $relative) {
            if (!is_string($relative)) {
                continue;
            }

            $saved = $backup.'/'.$relative;
            $live = $this->projectDir.'/'.$relative;

            if (is_file($saved)) {
                $this->fs->copy($saved, $live, true);
            } elseif (is_file($live)) {
                // In the manifest but absent from the backup means the patch
                // added it — undoing the patch means removing it again.
                $this->fs->remove($live);
            }
        }
    }

    private function record(PatchManifest $manifest): void
    {
        $ledger = $this->appliedLedger();
        $ledger[$manifest->version] = (new \DateTimeImmutable())->format(DATE_ATOM);

        $setting = $this->settings->findOneBy(['settingKey' => self::LEDGER_KEY]);

        if (!$setting instanceof Setting) {
            $setting = new Setting(self::LEDGER_KEY, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue(json_encode($ledger, JSON_THROW_ON_ERROR));
        $this->entityManager->flush();
    }
}
