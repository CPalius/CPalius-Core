<?php

declare(strict_types=1);

namespace App\Core\Update;

use Doctrine\DBAL\Connection;

/**
 * Before 2.2.6, ModuleInstallContext::splitSqlStatements() decided a chunk was
 * "just a comment" by checking whether the WHOLE chunk started with "--". A
 * migration file's comment sits directly above the statement it explains with
 * no ';' between them, so comment and statement landed in the same chunk and
 * the entire chunk — statement included — was silently discarded.
 *
 * Two shipped module migrations hit this:
 *   - Whitepaper 001_whitepaper.sql lost its first CREATE TABLE
 *     (cp_whitepaper_documents); only cp_whitepaper_sections was created.
 *   - Forum 20260916_forum_reputation_topic_url.sql lost the SET that reads
 *     whether the topic_url column already exists, so the guarded ALTER TABLE
 *     that follows it always evaluated the IF() against NULL and no-opped.
 *
 * Both files are already recorded as "applied" on any install that ran them
 * under the old parser, so applyPendingSqlMigrations() will never revisit
 * them on its own — the filename check happens before the file is even read.
 * This hook forgets just those two ledger entries so the very next module-
 * upgrade pass re-runs them through the fixed splitter. Safe to run any
 * number of times: both files are themselves idempotent (CREATE TABLE IF NOT
 * EXISTS; an information_schema-guarded ALTER TABLE).
 */
final class RepairDroppedCommentedSqlHook implements UpdateHookInterface
{
    /** @var array<string, string> module directory name => migration filename */
    private const TARGETS = [
        'Whitepaper' => '001_whitepaper.sql',
        'Forum' => '20260916_forum_reputation_topic_url.sql',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function id(): string
    {
        return 'core.2_2_7.repair_dropped_commented_sql_statements';
    }

    public function version(): string
    {
        return '2.2.7';
    }

    public function description(): string
    {
        return 'Re-queues module SQL migrations whose leading comment caused a statement to be silently dropped before 2.2.6.';
    }

    public function run(): ?string
    {
        $repaired = [];

        foreach (self::TARGETS as $dirName => $file) {
            if ($this->forgetAppliedFile($dirName, $file)) {
                $repaired[] = $dirName.'/'.$file;
            }
        }

        return $repaired === [] ? null : 'requeued '.implode(', ', $repaired);
    }

    private function forgetAppliedFile(string $dirName, string $file): bool
    {
        $key = 'module_lifecycle.'.strtolower($dirName).'.applied_sql';

        try {
            $raw = $this->connection->fetchOne(
                'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $key],
            );
        } catch (\Throwable) {
            return false;
        }

        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $applied = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        if (!\is_array($applied) || !\in_array($file, $applied, true)) {
            return false;
        }

        $remaining = array_values(array_filter(
            $applied,
            static fn (mixed $name): bool => $name !== $file,
        ));

        try {
            $this->connection->executeStatement(
                'UPDATE cp_settings SET setting_value = :value WHERE setting_key = :key',
                ['value' => json_encode($remaining, \JSON_THROW_ON_ERROR), 'key' => $key],
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
