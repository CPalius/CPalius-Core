<?php

declare(strict_types=1);

namespace App\Core\Module;

use Doctrine\DBAL\Connection;

/**
 * Everything an installer is allowed to touch. Deliberately narrow: installers run
 * while the module's own services may not be booted, so no container is exposed.
 */
final class ModuleInstallContext
{
    public function __construct(
        public readonly Connection $connection,
        public readonly ModuleManifest $manifest,
        public readonly string $moduleDir,
        public readonly string $projectDir,
    ) {
    }

    /**
     * Deletes every cp_settings row owned by this module.
     * The usual last step of uninstall(); settings have no foreign keys to cascade from.
     */
    public function purgeSettings(string $moduleId): int
    {
        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM cp_settings WHERE module = :module',
                ['module' => $moduleId],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Drops a table only if it exists, so uninstall stays safe to re-run.
     */
    public function dropTableIfExists(string $table): void
    {
        if (!$this->isSafeIdentifier($table)) {
            return;
        }

        try {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        } catch (\Throwable) {
            // Uninstall must never block deactivation.
        }
    }

    public function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run unapplied Resources/migrations/*.sql files in name order.
     * Applied filenames are stored in cp_settings so upgrade() is incremental.
     */
    public function applyPendingSqlMigrations(): int
    {
        $dir = $this->moduleDir.'/Resources/migrations';

        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir.'/*.sql') ?: [];
        sort($files, \SORT_STRING);

        $applied = $this->readAppliedSql();
        $ran = 0;

        foreach ($files as $path) {
            $name = basename($path);

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/', $name) !== 1) {
                continue;
            }

            if (\in_array($name, $applied, true)) {
                continue;
            }

            $sql = @file_get_contents($path);

            if ($sql === false) {
                continue;
            }

            foreach ($this->splitSqlStatements($sql) as $statement) {
                $this->connection->executeStatement($statement);
            }

            $applied[] = $name;
            $this->writeAppliedSql($applied);
            ++$ran;
        }

        return $ran;
    }

    public function forgetSqlMigrationState(): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM cp_settings WHERE setting_key = :key',
                ['key' => $this->appliedSqlKey()],
            );
        } catch (\Throwable) {
            // Uninstall must never block deactivation.
        }
    }

    /**
     * Guards against identifier injection: installers pass literal table names only.
     */
    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }

    /**
     * @return list<string>
     */
    private function readAppliedSql(): array
    {
        try {
            $raw = $this->connection->fetchOne(
                'SELECT setting_value FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $this->appliedSqlKey()],
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

        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param list<string> $applied
     */
    private function writeAppliedSql(array $applied): void
    {
        $json = json_encode($applied, \JSON_THROW_ON_ERROR);
        $key = $this->appliedSqlKey();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $exists = $this->connection->fetchOne(
                'SELECT id FROM cp_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $key],
            );

            if ($exists === false || $exists === null) {
                $this->connection->executeStatement(
                    'INSERT INTO cp_settings (setting_key, setting_value, module, updated_at) VALUES (:key, :value, :module, :now)',
                    ['key' => $key, 'value' => $json, 'module' => 'module_lifecycle', 'now' => $now],
                );

                return;
            }

            $this->connection->executeStatement(
                'UPDATE cp_settings SET setting_value = :value, updated_at = :now WHERE setting_key = :key',
                ['key' => $key, 'value' => $json, 'now' => $now],
            );
        } catch (\Throwable) {
            // Losing the marker means the file runs again; SQL files must be idempotent.
        }
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];

        foreach (preg_split('/;\s*(?:\r\n|\n|$)/', $sql) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }

            $statements[] = $chunk;
        }

        return $statements;
    }

    private function appliedSqlKey(): string
    {
        return 'module_lifecycle.'.strtolower($this->manifest->dirName).'.applied_sql';
    }
}
