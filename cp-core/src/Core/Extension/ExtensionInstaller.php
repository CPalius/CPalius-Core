<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Cache\CacheRebuildManager;
use App\Core\Module\ModulePackageContract;
use App\Core\Module\ModulePackageService;
use App\Core\Module\ModuleRegistry;
use App\Core\Theme\ThemePackageContract;
use App\Core\Theme\ThemePackageService;
use App\Core\Update\UpdateRunner;
use App\Core\Version\CpVersion;
use App\Core\Version\ReleaseChecker;
use App\Core\Version\Service\AtomicUpdateInstaller;
use App\Core\Version\Service\DatabaseUpdateGuard;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Installs and updates a free module or theme from the signed extension index.
 *
 * The package hash comes from the signed index, so the ZIP is checked before it is opened. An
 * update of an ACTIVE module runs under DatabaseUpdateGuard (database snapshot, 503 page, rollback of
 * files and database together); everything else is a file swap with a backup that is restored on failure.
 */
final class ExtensionInstaller
{
    private const MAX_BYTES = 33554432;
    private const DOWNLOAD_TIMEOUT = 120;

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $projectDir,
        private readonly ExtensionCatalogue $catalogue,
        private readonly ModulePackageService $modulePackages,
        private readonly ThemePackageService $themePackages,
        private readonly ModuleRegistry $modules,
        private readonly AtomicUpdateInstaller $installer,
        private readonly DatabaseUpdateGuard $guard,
        private readonly UpdateRunner $runner,
        private readonly CacheRebuildManager $cache,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * @param string $type "modules" or "themes"
     *
     * @return array{success: bool, message: string, needsApply: bool, wasUpdate: bool}
     */
    public function install(string $type, string $name): array
    {
        $fail = static fn (string $message): array => ['success' => false, 'message' => $message, 'needsApply' => false, 'wasUpdate' => false];

        if (!\in_array($type, ['modules', 'themes'], true) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            return $fail('Unknown extension.');
        }

        $entry = $this->catalogue->find($type, $name);
        $version = \is_array($entry) ? (string) ($entry['latest'] ?? '') : '';
        $meta = \is_array($entry) ? (array) ($entry['versions'][$version] ?? []) : [];

        if ($meta === []) {
            return $fail('This extension is not in the catalogue.');
        }

        if (!($entry['free'] ?? true)) {
            return $fail('Only free extensions can be installed from the panel.');
        }

        [$compatible, $problems] = $this->catalogue->compatibility($meta);

        if (!$compatible) {
            return $fail('Not compatible with this installation: '.implode(', ', $problems));
        }

        $zipUrl = ReleaseChecker::FEED.ltrim((string) ($meta['zip'] ?? ''), '/');
        $sha256 = strtolower((string) ($meta['sha256'] ?? ''));

        if (!str_starts_with($zipUrl, ReleaseChecker::FEED.$type.'/') || str_contains($zipUrl, '..') || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return $fail('The catalogue entry has no usable package address or checksum.');
        }

        $target = $this->projectDir.'/cp-content/'.$type.'/'.$name;
        $isUpdate = is_dir($target);
        $active = $type === 'modules' && $this->isActive($name);
        $guarded = $isUpdate && $active;

        @set_time_limit(0);
        ignore_user_abort(true);

        $work = $this->installer->updateDir().'/ext';
        $zipPath = $work.'/'.$name.'-'.$version.'.zip';
        $extractDir = $work.'/x-'.bin2hex(random_bytes(6));
        $stage = $work.'/s-'.bin2hex(random_bytes(6));
        $backup = $this->installer->backupDir('ext-'.$type.'-'.$name.'-'.$version);
        $ownsLock = false;
        $began = false;

        try {
            $this->fs->mkdir($work);
            $this->download($zipUrl, $zipPath);

            if (!hash_equals($sha256, (string) hash_file('sha256', $zipPath))) {
                throw new \RuntimeException('Checksum mismatch: the package is not what the signed catalogue described.');
            }

            $source = $this->unpack($type, $zipPath, $extractDir, $name);

            if ($guarded) {
                $this->guard->begin($name.'@'.$version, $backup);
                $began = true;
            } else {
                $this->installer->acquireLock();
                $ownsLock = true;
            }

            $relative = 'cp-content/'.$type.'/'.$name;
            $this->fs->mirror($source, $stage.'/'.$relative);
            $newFiles = $this->listFiles($stage, $relative);
            $oldFiles = $isUpdate ? $this->listFiles($this->projectDir, $relative) : [];

            $this->backupFiles($oldFiles, array_values(array_unique(array_merge($oldFiles, $newFiles))), $backup);
            $this->installer->installAtomically($stage, $newFiles);

            // Files the new version no longer ships must not linger inside the module directory.
            foreach (array_diff($oldFiles, $newFiles) as $stale) {
                @unlink($this->projectDir.'/'.$stale);
            }

            if ($guarded) {
                $this->guard->advance(AtomicUpdateInstaller::STAGE_MIGRATING);
                $result = $this->runner->migrateOnly();

                if ($result->isFailure()) {
                    throw new \RuntimeException('Database migration failed: '.$result->summary);
                }

                $this->guard->advance(AtomicUpdateInstaller::STAGE_AWAITING_HOOKS);
                $this->cache->afterCodeUpdate();
            } elseif ($isUpdate) {
                $this->cache->afterCodeUpdate();
            }
        } catch (\Throwable $e) {
            if ($began && $this->guard->active()) {
                try {
                    $this->guard->rollback($e->getMessage(), false);
                } catch (\Throwable) {
                    // The guard keeps its state and retries the database restore on the next request.
                }
            } elseif (is_dir($backup)) {
                $this->installer->restoreFromBackup($backup);
            }

            $this->cleanup($zipPath, $extractDir, $stage, $ownsLock);

            return $fail($e->getMessage());
        }

        $this->cleanup($zipPath, $extractDir, $stage, $ownsLock);

        return [
            'success' => true,
            'message' => $isUpdate ? $name.' '.$version.' installed.' : $name.' '.$version.' downloaded.',
            'needsApply' => $guarded,
            'wasUpdate' => $isUpdate,
        ];
    }

    private function isActive(string $name): bool
    {
        foreach ($this->modules->discoverAllModules() as $module) {
            if ($module['dirName'] === $name) {
                return $module['status'] === 'active';
            }
        }

        return false;
    }

    private function download(string $url, string $target): void
    {
        $client = HttpClient::create(['timeout' => self::DOWNLOAD_TIMEOUT, 'max_duration' => self::DOWNLOAD_TIMEOUT * 2, 'headers' => ['User-Agent' => 'CPalius/'.CpVersion::VERSION]]);
        $response = $client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Download failed: HTTP '.$response->getStatusCode());
        }

        $handle = fopen($target, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot write '.$target);
        }

        $written = 0;

        try {
            foreach ($client->stream($response) as $chunk) {
                $written += (int) fwrite($handle, $chunk->getContent());

                if ($written > self::MAX_BYTES) {
                    throw new \RuntimeException('The package is larger than the allowed size.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extracts the ZIP with the same safety checks as a manual upload and returns the directory holding the extension.
     */
    private function unpack(string $type, string $zipPath, string $extractDir, string $name): string
    {
        if ($type === 'modules') {
            $result = $this->modulePackages->extractZip($zipPath, $extractDir);
            $dir = $result['moduleDir'] ?? null;
            $contract = $dir !== null ? ModulePackageContract::problems($dir) : [];
        } else {
            $result = $this->themePackages->extractZip($zipPath, $extractDir);
            $dir = $result['themeDir'] ?? null;
            $contract = $dir !== null ? ThemePackageContract::problems($dir) : [];
        }

        if (!$result['success'] || $dir === null) {
            $detail = implode(' ', \array_slice($result['problems'] ?? [], 0, 3));

            throw new \RuntimeException(($result['message'] ?? 'The package could not be extracted.').($detail !== '' ? ' '.$detail : ''));
        }

        if (($result['dirName'] ?? null) !== $name) {
            throw new \RuntimeException('The package contains a different extension than the catalogue said.');
        }

        if ($contract !== []) {
            throw new \RuntimeException('The package does not satisfy the extension contract: '.implode('; ', $contract));
        }

        return $dir;
    }

    /**
     * @return list<string> paths relative to $base
     */
    private function listFiles(string $base, string $relative): array
    {
        $root = $base.'/'.$relative;

        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($items as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $files[] = $relative.'/'.ltrim(str_replace('\\', '/', substr($item->getPathname(), \strlen($root))), '/');
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Copies the current files aside. The manifest also lists files the update adds, so a restore removes them again.
     *
     * @param list<string> $existing files that exist now
     * @param list<string> $manifest every path the update touches
     */
    private function backupFiles(array $existing, array $manifest, string $backup): void
    {
        $this->fs->remove($backup);

        foreach ($existing as $relative) {
            $this->fs->copy($this->projectDir.'/'.$relative, $backup.'/'.$relative, true);
        }

        $this->fs->dumpFile($backup.'/.manifest.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    private function cleanup(string $zipPath, string $extractDir, string $stage, bool $releaseLock): void
    {
        $this->fs->remove([$zipPath, $extractDir, $stage]);

        if ($releaseLock) {
            $this->installer->releaseLock();
        }
    }
}
