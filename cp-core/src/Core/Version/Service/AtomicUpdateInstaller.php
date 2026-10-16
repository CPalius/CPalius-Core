<?php

declare(strict_types=1);

namespace App\Core\Version\Service;

/**
 * File-level safety for updates: flock, 503 maintenance flag, two-phase atomic
 * install and crash self-recovery. Plain PHP only, so public/index.php can load it unbooted.
 */
final class AtomicUpdateInstaller
{
    public const STAGE_SNAPSHOT = 'snapshot';
    public const STAGE_FILES = 'files';
    public const STAGE_MIGRATING = 'migrating';
    public const STAGE_AWAITING_HOOKS = 'awaiting_hooks';
    public const STAGE_HOOKS = 'hooks';
    public const STAGE_NEEDS_DB_RESTORE = 'needs_db_restore';

    /** Stages where the database may already differ from the snapshot. */
    private const DB_TOUCHED_STAGES = [
        self::STAGE_MIGRATING,
        self::STAGE_AWAITING_HOOKS,
        self::STAGE_HOOKS,
        self::STAGE_NEEDS_DB_RESTORE,
    ];

    /** An update parked between its two requests for longer than this is abandoned. */
    private const AWAITING_TTL_SECONDS = 900;

    private const TEMP_SUFFIX = '.cpnew';

    /** @var resource|null */
    private $lock;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function updateDir(): string
    {
        return $this->projectDir.'/cp-core/var/update';
    }

    public function backupDir(string $version): string
    {
        return $this->updateDir().'/backup-'.$version;
    }

    public function statePath(): string
    {
        return $this->updateDir().'/.state.json';
    }

    public function markerPath(): string
    {
        return $this->updateDir().'/.in-progress.json';
    }

    public function maintenancePath(): string
    {
        return $this->updateDir().'/.maintenance';
    }

    public function lastRollbackPath(): string
    {
        return $this->updateDir().'/last-rollback.json';
    }

    public function acquireLock(): void
    {
        if ($this->lock !== null) {
            return;
        }

        $this->ensureDir($this->updateDir());
        $handle = fopen($this->updateDir().'/.lock', 'c');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open the update lock file.');
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            throw new \RuntimeException('Another update is already running.');
        }

        $this->lock = $handle;
    }

    public function releaseLock(): void
    {
        if ($this->lock === null) {
            return;
        }

        flock($this->lock, \LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }

    public function holdsLock(): bool
    {
        return $this->lock !== null;
    }

    public function enterMaintenance(): void
    {
        $this->ensureDir($this->updateDir());
        file_put_contents($this->maintenancePath(), json_encode(['since' => date(\DATE_ATOM)]));
    }

    public function leaveMaintenance(): void
    {
        if (is_file($this->maintenancePath())) {
            @unlink($this->maintenancePath());
        }
    }

    public function maintenanceActive(): bool
    {
        return is_file($this->maintenancePath());
    }

    /**
     * @return array<string, mixed>|null null when no guarded update exists
     */
    public function state(): ?array
    {
        $path = $this->statePath();

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return \is_array($data) ? $data : ['stage' => 'unknown', 'updated_at' => 0];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function writeState(array $state): void
    {
        $state['updated_at'] = time();
        $this->ensureDir($this->updateDir());

        // Written beside the target and renamed, so a crash never leaves half a JSON file.
        $tmp = $this->statePath().self::TEMP_SUFFIX;
        file_put_contents($tmp, json_encode($state, \JSON_THROW_ON_ERROR));
        $this->moveOver($tmp, $this->statePath());
    }

    public function clearState(): void
    {
        if (is_file($this->statePath())) {
            @unlink($this->statePath());
        }
    }

    public function isDatabaseTouched(string $stage): bool
    {
        return \in_array($stage, self::DB_TOUCHED_STAGES, true);
    }

    /**
     * Copies every file next to its target first, then renames them all into place.
     * A failure before the rename loop leaves the live tree untouched.
     *
     * @param list<string> $files archive-relative paths
     */
    public function installAtomically(string $root, array $files): int
    {
        $pending = [];

        try {
            foreach ($files as $relative) {
                $target = $this->projectDir.'/'.$relative;
                $this->ensureDir(\dirname($target));

                if (!copy($root.'/'.$relative, $target.self::TEMP_SUFFIX)) {
                    @unlink($target.self::TEMP_SUFFIX);

                    throw new \RuntimeException('Cannot stage '.$relative);
                }

                $pending[] = $target;
            }
        } catch (\Throwable $e) {
            $this->discardTemps($pending);

            throw $e;
        }

        $written = 0;

        try {
            foreach ($pending as $target) {
                $this->moveOver($target.self::TEMP_SUFFIX, $target);
                ++$written;
            }
        } catch (\Throwable $e) {
            $this->discardTemps(\array_slice($pending, $written));

            throw $e;
        }

        return $written;
    }

    /**
     * Puts back every file listed in the backup manifest and removes files the release added.
     */
    public function restoreFromBackup(string $backup): bool
    {
        $manifest = $backup.'/.manifest.json';

        if ($backup === '' || !is_file($manifest)) {
            return false;
        }

        $files = json_decode((string) file_get_contents($manifest), true);

        if (!\is_array($files)) {
            return false;
        }

        foreach ($files as $relative) {
            if (!\is_string($relative) || str_contains($relative, '..')) {
                continue;
            }

            $saved = $backup.'/'.$relative;
            $live = $this->projectDir.'/'.$relative;

            if (is_file($saved)) {
                $this->ensureDir(\dirname($live));
                copy($saved, $live.self::TEMP_SUFFIX);
                $this->moveOver($live.self::TEMP_SUFFIX, $live);
            } elseif (is_file($live)) {
                @unlink($live);
            }
        }

        return true;
    }

    /**
     * Empties cp-core/var/cache so the next request compiles a container for the files on disk.
     */
    public function purgeCaches(): void
    {
        $cache = $this->projectDir.'/cp-core/var/cache';

        if (!is_dir($cache)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }

    /**
     * Front-controller hook: answers 503 while an update runs and undoes one whose process died.
     */
    public function guardRequest(): void
    {
        if (!is_file($this->statePath()) && !is_file($this->markerPath())) {
            return;
        }

        $handle = @fopen($this->updateDir().'/.lock', 'c');

        if ($handle === false || !flock($handle, \LOCK_EX | \LOCK_NB)) {
            $this->serviceUnavailable();
        }

        try {
            $this->recoverDeadUpdate();
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    private function recoverDeadUpdate(): void
    {
        $state = $this->state();

        if ($state === null) {
            return;
        }

        $stage = (string) ($state['stage'] ?? 'unknown');
        $age = time() - (int) ($state['updated_at'] ?? 0);

        // Between the migration request and the hooks request nobody holds the lock on purpose.
        if ($stage === self::STAGE_NEEDS_DB_RESTORE
            || ($stage === self::STAGE_AWAITING_HOOKS && $age < self::AWAITING_TTL_SECONDS)) {
            return;
        }

        $backup = \is_string($state['backup'] ?? null) ? $state['backup'] : '';
        $this->restoreFromBackup($backup);
        @unlink($this->markerPath());
        $this->purgeCaches();

        $databasePending = $this->isDatabaseTouched($stage) && \is_string($state['snapshot'] ?? null);
        $record = [
            'at' => date(\DATE_ATOM),
            'version' => (string) ($state['version'] ?? ''),
            'reason' => 'The update process stopped unexpectedly ('.$stage.').',
            'files' => $backup !== '',
            'database' => $databasePending,
            'database_pending' => $databasePending,
        ];
        file_put_contents($this->lastRollbackPath(), json_encode($record));

        if ($databasePending) {
            $state['stage'] = self::STAGE_NEEDS_DB_RESTORE;
            $this->writeState($state);

            return;
        }

        $this->clearState();
        $this->leaveMaintenance();
    }

    private function serviceUnavailable(): never
    {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store, private');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>503</title>'
            .'<p style="font:16px sans-serif;max-width:32em;margin:15vh auto">'
            .'The site is being updated and will be back in a moment.<br>'
            .'Site güncelleniyor, birazdan geri döneceğiz.</p>';

        exit;
    }

    /**
     * rename() replaces atomically; the copy fallback covers Windows sharing violations.
     */
    private function moveOver(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        if (!copy($from, $to)) {
            @unlink($from);

            throw new \RuntimeException('Cannot write '.$to);
        }

        @unlink($from);
    }

    /**
     * @param list<string> $targets
     */
    private function discardTemps(array $targets): void
    {
        foreach ($targets as $target) {
            @unlink($target.self::TEMP_SUFFIX);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create '.$dir);
        }
    }
}
