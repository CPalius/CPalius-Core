<?php

declare(strict_types=1);

namespace App\Core\Backup;

use Doctrine\DBAL\Connection;

/**
 * Creates, lists, and deletes operator backups under cp-core/var/backups/.
 */
final class BackupService
{
    private readonly string $backupDir;
    private readonly DatabaseDumper $dumper;
    private readonly BackupFileCollector $collector;

    public function __construct(
        private readonly Connection $connection,
        string $projectDir,
        ?string $backupDir = null,
        private readonly ?BackupShipper $shipper = null,
    ) {
        $normalizedProject = rtrim(str_replace('\\', '/', $projectDir), '/');
        $this->backupDir = $backupDir !== null && $backupDir !== ''
            ? rtrim(str_replace('\\', '/', $backupDir), '/')
            : $normalizedProject.'/cp-core/var/backups';
        $this->dumper = new DatabaseDumper($this->connection);
        $this->collector = new BackupFileCollector($normalizedProject);
    }

    public function backupDirectory(): string
    {
        return $this->backupDir;
    }

    public function create(string $type): BackupArchive
    {
        if (!\in_array($type, BackupFilename::TYPES, true)) {
            throw new BackupException(sprintf('Unknown backup type "%s".', $type));
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $this->ensureBackupDir();
        $filename = BackupFilename::create($type, new \DateTimeImmutable());
        $target = $this->join($this->backupDir, $filename);

        try {
            match ($type) {
                BackupFilename::TYPE_DB => $this->dumper->dumpToGzip($target),
                BackupFilename::TYPE_FILES => $this->writeFilesZip($target),
                BackupFilename::TYPE_FULL => $this->writeFullZip($target),
                default => throw new BackupException(sprintf('Unknown backup type "%s".', $type)),
            };
        } catch (\Throwable $e) {
            if (is_file($target)) {
                @unlink($target);
            }

            throw $e instanceof BackupException ? $e : new BackupException($e->getMessage(), 0, $e);
        }

        return $this->archiveFromFile($filename, $target);
    }

    /**
     * Sends a finished archive off-site, applies remote retention, and — only
     * on proven success — drops the local copy when the operator asked for
     * that.
     *
     * Deliberately NOT folded into create(). Creating the archive and getting it
     * off the box are two outcomes an operator needs told apart: a 4 GB full
     * backup that was written correctly and then failed to upload is a partial
     * success, and a create() that reported failure for it would invite the
     * operator to run the expensive half all over again. So create() stays about
     * the archive, this is about where it goes, and the two report separately.
     *
     * @return array{shipped: bool, pruned: int, local_removed: bool, target: ?string, error: ?string}
     */
    public function shipAndPrune(BackupArchive $archive): array
    {
        $result = ['shipped' => false, 'pruned' => 0, 'local_removed' => false, 'target' => null, 'error' => null];

        if ($this->shipper === null) {
            return $result;
        }

        $path = $this->absolutePath($archive->filename);

        try {
            $result['shipped'] = $this->shipper->ship($archive->filename, $path);
        } catch (BackupException $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        if (!$result['shipped']) {
            return $result;
        }

        $result['target'] = $this->shipper->targetLabel();
        $result['pruned'] = $this->shipper->prune();

        // The order is the safety property: ship() already read the object back
        // from the destination, so by the time this runs there are provably two
        // copies and removing one of them is a space decision, not a risk.
        if (!$this->shipper->keepLocal() && is_file($path) && @unlink($path)) {
            $result['local_removed'] = true;
        }

        return $result;
    }

    /**
     * @return list<BackupArchive>
     */
    public function list(): array
    {
        $this->ensureBackupDir();

        $paths = array_merge(
            glob($this->backupDir.'/cpalius-db-*.sql.gz') ?: [],
            glob($this->backupDir.'/cpalius-files-*.zip') ?: [],
            glob($this->backupDir.'/cpalius-full-*.zip') ?: [],
        );

        $archives = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            if (!BackupFilename::isValid($filename) || !is_file($path)) {
                continue;
            }

            $archives[] = $this->archiveFromFile($filename, $path);
        }

        usort(
            $archives,
            static fn (BackupArchive $a, BackupArchive $b): int => $b->createdAt <=> $a->createdAt,
        );

        return $archives;
    }

    public function delete(string $filename): void
    {
        $path = $this->absolutePath($filename);
        if (!is_file($path)) {
            throw new BackupException(sprintf('Backup archive "%s" was not found.', $filename));
        }

        if (!@unlink($path)) {
            throw new BackupException(sprintf('Backup archive "%s" could not be deleted.', $filename));
        }
    }

    public function absolutePath(string $filename): string
    {
        if (!BackupFilename::isValid($filename)) {
            throw new BackupException('That archive name is not allowed.');
        }

        $this->ensureBackupDir();
        $path = $this->join($this->backupDir, $filename);
        if (!$this->isInsideBackupDir($path)) {
            throw new BackupException('That archive name is not allowed.');
        }

        return $path;
    }

    private function writeFilesZip(string $target): void
    {
        $zip = $this->openZip($target);
        try {
            $this->addProjectFiles($zip);
        } finally {
            $zip->close();
        }
    }

    private function writeFullZip(string $target): void
    {
        $tempDump = $this->join($this->backupDir, 'tmp-'.bin2hex(random_bytes(8)).'.sql.gz');
        $zip = $this->openZip($target);

        try {
            $this->dumper->dumpToGzip($tempDump);
            if (!$zip->addFile($tempDump, 'database.sql.gz')) {
                throw new BackupException('The database dump could not be added to the archive.');
            }
            $this->addProjectFiles($zip);
        } finally {
            $zip->close();
            if (is_file($tempDump)) {
                @unlink($tempDump);
            }
        }
    }

    private function addProjectFiles(\ZipArchive $zip): void
    {
        $added = 0;
        foreach ($this->collector->collect() as $entry) {
            if (!$zip->addFile($entry['absolute'], $entry['relative'])) {
                continue;
            }
            ++$added;
        }

        if ($added === 0) {
            throw new BackupException('No project files were eligible for the archive.');
        }
    }

    private function openZip(string $target): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new BackupException('The PHP zip extension is required to create file backups.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('The ZIP archive could not be created.');
        }

        return $zip;
    }

    private function archiveFromFile(string $filename, string $path): BackupArchive
    {
        $type = BackupFilename::typeFrom($filename);
        if ($type === null) {
            throw new BackupException('That archive name is not allowed.');
        }

        $mtime = filemtime($path);
        $size = filesize($path);

        return new BackupArchive(
            $filename,
            $type,
            \is_int($size) ? $size : 0,
            (new \DateTimeImmutable())->setTimestamp(\is_int($mtime) ? $mtime : time()),
        );
    }

    private function ensureBackupDir(): void
    {
        if (is_dir($this->backupDir)) {
            return;
        }

        if (!@mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new BackupException('The backup directory could not be created.');
        }
    }

    private function isInsideBackupDir(string $path): bool
    {
        $dir = str_replace('\\', '/', $this->backupDir);
        $candidate = str_replace('\\', '/', $path);
        $prefix = rtrim($dir, '/').'/';

        return str_starts_with($candidate, $prefix) && !str_contains(substr($candidate, strlen($prefix)), '/');
    }

    private function join(string $dir, string $filename): string
    {
        return rtrim($dir, '/').'/'.$filename;
    }
}
