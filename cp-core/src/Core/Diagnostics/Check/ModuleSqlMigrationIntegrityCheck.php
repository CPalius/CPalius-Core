<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Module\ModuleRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verifies that every module SQL migration marked "applied" actually produced
 * the table/column it promised — not just that its filename is in the ledger.
 *
 * This exists because PendingMigrationsCheck and PendingUpdatesCheck can only
 * ask the bookkeeping a question it always answers "yes" to: "is the filename
 * recorded as applied?". Neither can catch a migration that ran, was marked
 * applied, and silently produced less than it claimed — which is exactly what
 * happened before 2.2.6: a SQL-comment parsing bug in
 * ModuleInstallContext::splitSqlStatements() dropped a CREATE TABLE statement
 * that sat directly under a comment, the file was still marked applied, and
 * nothing anywhere ever asked whether the table actually existed. The gap was
 * invisible to every existing check because every existing check only reads
 * the ledger, never the schema the ledger claims to describe.
 *
 * Detection is intentionally cheap and approximate: a regex lift of
 * `CREATE TABLE [IF NOT EXISTS] name` and `ALTER TABLE name ADD COLUMN name`
 * out of each applied *.sql file, checked against information_schema. It will
 * not catch every possible drift (a dropped ALTER that isn't a plain ADD
 * COLUMN, for instance), but it catches the exact class of bug that shipped
 * silently for two releases, and costs nothing on a healthy installation.
 */
final class ModuleSqlMigrationIntegrityCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/cp-content/modules')]
        private readonly string $modulesDir,
    ) {
    }

    public function key(): string
    {
        return 'module_sql_integrity';
    }

    public function run(): array
    {
        $findings = [];

        foreach ($this->modules->discoverAllModules() as $module) {
            if ($module['status'] !== 'active') {
                continue;
            }

            $findings = [...$findings, ...$this->checkModule($module['dirName'])];
        }

        if ($findings === []) {
            return [DoctorFinding::pass(
                'module_sql_integrity.drift',
                'Module SQL migration integrity',
                'Every applied module migration produced the tables/columns it declares.',
            )];
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkModule(string $dirName): array
    {
        $migrationsDir = $this->modulesDir.'/'.$dirName.'/Resources/migrations';

        if (!is_dir($migrationsDir)) {
            return [];
        }

        $applied = $this->appliedFiles($dirName);

        if ($applied === []) {
            return [];
        }

        $findings = [];

        foreach ($applied as $file) {
            $path = $migrationsDir.'/'.$file;

            if (!is_file($path)) {
                continue;
            }

            $sql = $this->stripComments((string) @file_get_contents($path));

            foreach ($this->promisedTables($sql) as $table) {
                if (!$this->tableExists($table)) {
                    $findings[] = new DoctorFinding(
                        id: 'module_sql_integrity.missing_table',
                        severity: DoctorFinding::SEVERITY_HIGH,
                        title: 'A module migration is marked applied but its table is missing',
                        detail: sprintf('%s/%s claims CREATE TABLE %s, but that table does not exist.', $dirName, $file, $table),
                        remedy: 'Recreate the table by hand (the CREATE TABLE statement in the migration is safe to re-run, it uses IF NOT EXISTS), or add an update hook that forgets the ledger entry so cp:update retries the file.',
                    );
                }
            }

            foreach ($this->promisedColumns($sql) as [$table, $column]) {
                if ($this->tableExists($table) && !$this->columnExists($table, $column)) {
                    $findings[] = new DoctorFinding(
                        id: 'module_sql_integrity.missing_column',
                        severity: DoctorFinding::SEVERITY_HIGH,
                        title: 'A module migration is marked applied but its column is missing',
                        detail: sprintf('%s/%s claims ADD COLUMN %s.%s, but that column does not exist.', $dirName, $file, $table, $column),
                        remedy: sprintf('Add the column by hand, or add an update hook that forgets the ledger entry for %s so cp:update retries the file.', $file),
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function appliedFiles(string $dirName): array
    {
        $key = 'module_lifecycle.'.strtolower($dirName).'.applied_sql';

        try {
            $raw = $this->connection->fetchOne(
                'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $key],
            );
        } catch (\Throwable) {
            return [];
        }

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * These migration files are full of prose comments explaining WHY, and
     * that prose freely uses words like "create table" in plain English —
     * matching the promise regexes against raw text would misread a comment
     * as a statement. Block and line comments are stripped first so only
     * actual SQL is inspected.
     */
    private function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;

        return $sql;
    }

    /**
     * @return list<string>
     */
    private function promisedTables(string $sql): array
    {
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $matches) < 1) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function promisedColumns(string $sql): array
    {
        // COLUMN is required in the match on purpose: "ADD INDEX", "ADD
        // CONSTRAINT", "ADD UNIQUE", "ADD FOREIGN KEY" are all valid ALTER
        // TABLE ADD clauses that are not a column at all, and this codebase's
        // migrations always spell out COLUMN explicitly for the ones that are.
        if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/i', $sql, $matches) < 1) {
            return [];
        }

        $pairs = [];
        foreach ($matches[1] as $i => $table) {
            $pairs[] = [$table, $matches[2][$i]];
        }

        return $pairs;
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
                ['table' => $table, 'column' => $column],
            );

            return (int) $count > 0;
        } catch (\Throwable) {
            return true; // Unreadable is not "missing" — never false-positive a HIGH finding off a broken query.
        }
    }
}
