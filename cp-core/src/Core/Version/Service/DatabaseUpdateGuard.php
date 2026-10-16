<?php

declare(strict_types=1);

namespace App\Core\Version\Service;

use App\Core\Backup\DatabaseDumper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Snapshots the database before an update and undoes database plus files together
 * when a migration or hook step fails.
 */
final class DatabaseUpdateGuard
{
    /** A rollback notice older than this is no longer news. */
    private const NOTICE_TTL_SECONDS = 604800;

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly Connection $connection,
        private readonly AtomicUpdateInstaller $installer,
    ) {
    }

    public function active(): bool
    {
        return $this->installer->state() !== null;
    }

    public function stageIs(string $stage): bool
    {
        return ($this->installer->state()['stage'] ?? null) === $stage;
    }

    /**
     * Locks, switches on 503 maintenance and takes the snapshot. Pass a backup
     * directory when file rollback is wanted too.
     */
    public function begin(string $version, ?string $backupDir): void
    {
        if ($this->installer->state() !== null) {
            throw new \RuntimeException('An earlier update has not finished yet.');
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $this->installer->acquireLock();

        try {
            $snapshot = $this->installer->updateDir().'/db-'.$version.'.sql.gz';
            $state = [
                'version' => $version,
                'stage' => AtomicUpdateInstaller::STAGE_SNAPSHOT,
                'backup' => $backupDir ?? '',
                'snapshot' => $snapshot,
                'tables' => [],
                'started_at' => date(\DATE_ATOM),
            ];

            $this->installer->enterMaintenance();
            $this->installer->writeState($state);

            $this->dumper->dumpToGzip($snapshot);

            $state['tables'] = $this->connection->createSchemaManager()->listTableNames();
            $state['stage'] = AtomicUpdateInstaller::STAGE_FILES;
            $this->installer->writeState($state);
        } catch (\Throwable $e) {
            $this->installer->clearState();
            $this->installer->leaveMaintenance();
            $this->installer->releaseLock();

            throw $e;
        }
    }

    /**
     * Moves to the next stage. Re-takes the lock when a new request continues a parked update.
     */
    public function advance(string $stage): void
    {
        $state = $this->installer->state();

        if ($state === null) {
            throw new \RuntimeException('No guarded update is in progress.');
        }

        $this->installer->acquireLock();
        $state['stage'] = $stage;
        $this->installer->writeState($state);

        // Parked between requests: the lock is released, the 503 flag and state stay.
        if ($stage === AtomicUpdateInstaller::STAGE_AWAITING_HOOKS) {
            $this->installer->releaseLock();
        }
    }

    /**
     * Everything worked: keep the newest snapshot, drop state, reopen the site.
     */
    public function commit(): void
    {
        $state = $this->installer->state();
        $snapshot = \is_array($state) && \is_string($state['snapshot'] ?? null) ? $state['snapshot'] : '';

        if ($snapshot !== '' && is_file($snapshot)) {
            $last = $this->installer->updateDir().'/db-last.sql.gz';
            @unlink($last);
            @rename($snapshot, $last);
        }

        @unlink($this->installer->lastRollbackPath());
        $this->installer->clearState();
        $this->installer->leaveMaintenance();
        $this->installer->releaseLock();
    }

    /**
     * Restores files, then the database. Throws if the database cannot be restored;
     * the state then stays so the next request retries.
     *
     * @return array{files: bool, database: bool}
     */
    public function rollback(string $reason, bool $purgeCache = true): array
    {
        $state = $this->installer->state();

        if ($state === null) {
            return ['files' => false, 'database' => false];
        }

        @set_time_limit(0);
        ignore_user_abort(true);
        $this->installer->acquireLock();

        $stage = (string) ($state['stage'] ?? '');
        $backup = \is_string($state['backup'] ?? null) ? $state['backup'] : '';
        $snapshot = \is_string($state['snapshot'] ?? null) ? $state['snapshot'] : '';

        $files = $this->installer->restoreFromBackup($backup);
        @unlink($this->installer->markerPath());

        if ($purgeCache) {
            $this->installer->purgeCaches();
        }

        $database = false;

        if ($this->installer->isDatabaseTouched($stage) && is_file($snapshot)) {
            try {
                $this->restoreSnapshot($snapshot, $this->tableList($state));
                $database = true;
            } catch (\Throwable $e) {
                $state['stage'] = AtomicUpdateInstaller::STAGE_NEEDS_DB_RESTORE;
                $this->installer->writeState($state);
                $this->writeNotice($state, $reason, $files, false, true);
                $this->installer->releaseLock();

                throw new \RuntimeException('Files were restored but the database restore failed: '.$e->getMessage(), 0, $e);
            }
        }

        $this->writeNotice($state, $reason, $files, $database, false);
        $this->installer->clearState();
        $this->installer->leaveMaintenance();
        $this->installer->releaseLock();

        return ['files' => $files, 'database' => $database];
    }

    /**
     * Finishes a database restore that a crashed update left pending (called on the first booted request).
     */
    public function completePendingRestore(): void
    {
        $state = $this->installer->state();

        if (!\is_array($state) || ($state['stage'] ?? null) !== AtomicUpdateInstaller::STAGE_NEEDS_DB_RESTORE) {
            return;
        }

        $snapshot = \is_string($state['snapshot'] ?? null) ? $state['snapshot'] : '';

        try {
            $this->installer->acquireLock();
        } catch (\RuntimeException) {
            return;
        }

        try {
            if (is_file($snapshot)) {
                $this->restoreSnapshot($snapshot, $this->tableList($state));
            }

            $notice = $this->notice() ?? [];
            $notice['database'] = is_file($snapshot);
            $notice['database_pending'] = false;
            file_put_contents($this->installer->lastRollbackPath(), json_encode($notice));
            $this->installer->clearState();
            $this->installer->leaveMaintenance();
        } finally {
            $this->installer->releaseLock();
        }
    }

    /**
     * The last automatic rollback, for the AACP banner, or null when there is nothing to show.
     *
     * @return array<string, mixed>|null
     */
    public function notice(): ?array
    {
        $path = $this->installer->lastRollbackPath();

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!\is_array($data) || time() - (int) strtotime((string) ($data['at'] ?? '')) > self::NOTICE_TTL_SECONDS) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<string>
     */
    private function tableList(array $state): array
    {
        return array_values(array_filter((array) ($state['tables'] ?? []), 'is_string'));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeNotice(array $state, string $reason, bool $files, bool $database, bool $databasePending): void
    {
        file_put_contents($this->installer->lastRollbackPath(), json_encode([
            'at' => date(\DATE_ATOM),
            'version' => (string) ($state['version'] ?? ''),
            'reason' => $reason,
            'files' => $files,
            'database' => $database,
            'database_pending' => $databasePending,
        ]));
    }

    /**
     * Replays the gzipped dump, then drops tables the failed update created.
     *
     * @param list<string> $knownTables tables that existed when the snapshot was taken
     */
    private function restoreSnapshot(string $snapshot, array $knownTables): void
    {
        $handle = gzopen($snapshot, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot read the database snapshot.');
        }

        $mysql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;

        try {
            foreach ($this->statements($handle, $mysql) as $sql) {
                $this->connection->executeStatement($sql);
            }

            if ($knownTables !== []) {
                $this->dropUnknownTables($knownTables, $mysql);
            }
        } finally {
            gzclose($handle);

            if ($mysql) {
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * @param list<string> $knownTables
     */
    private function dropUnknownTables(array $knownTables, bool $mysql): void
    {
        if ($mysql) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            if (!\in_array($table, $knownTables, true) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1) {
                $quoted = $mysql ? '`'.$table.'`' : '"'.$table.'"';
                $this->connection->executeStatement('DROP TABLE IF EXISTS '.$quoted);
            }
        }
    }

    /**
     * Splits the dump into statements without breaking on semicolons inside string literals.
     *
     * @param resource $handle
     *
     * @return \Generator<int, string>
     */
    private function statements($handle, bool $backslashEscapes): \Generator
    {
        $buffer = '';
        $inQuote = false;
        $escapePending = false;
        $quoteStops = $backslashEscapes ? "'\\" : "'";

        while (!gzeof($handle)) {
            $chunk = gzread($handle, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $length = \strlen($chunk);
            $pos = 0;

            while ($pos < $length) {
                if ($escapePending) {
                    $buffer .= $chunk[$pos++];
                    $escapePending = false;

                    continue;
                }

                if ($inQuote) {
                    $run = strcspn($chunk, $quoteStops, $pos);
                    $buffer .= substr($chunk, $pos, $run);
                    $pos += $run;

                    if ($pos >= $length) {
                        break;
                    }

                    $char = $chunk[$pos++];
                    $buffer .= $char;

                    if ($char === '\\') {
                        $escapePending = true;
                    } else {
                        $inQuote = false;
                    }

                    continue;
                }

                $run = strcspn($chunk, "';", $pos);
                $buffer .= substr($chunk, $pos, $run);
                $pos += $run;

                if ($pos >= $length) {
                    break;
                }

                $char = $chunk[$pos++];

                if ($char === "'") {
                    $buffer .= $char;
                    $inQuote = true;

                    continue;
                }

                $statement = $this->stripLeadingComments($buffer);
                $buffer = '';

                if ($statement !== '') {
                    yield $statement;
                }
            }
        }

        $tail = $this->stripLeadingComments($buffer);

        if ($tail !== '') {
            yield $tail;
        }
    }

    private function stripLeadingComments(string $sql): string
    {
        $sql = ltrim($sql);

        while (str_starts_with($sql, '--')) {
            $newline = strpos($sql, "\n");
            $sql = $newline === false ? '' : ltrim(substr($sql, $newline + 1));
        }

        return $sql;
    }
}
