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
     * Publishes a front-end entry point into a site menu, so activating a module
     * that adds public pages also makes them reachable.
     *
     * Without this, a module can be installed, activated and completely invisible:
     * its pages exist but nothing links to them, and the operator has to know the
     * URL and add a menu item by hand before any visitor can arrive.
     *
     * Deliberately guarded rather than a hard dependency — the menu tables belong
     * to the Menu module, so when that module is absent this is a no-op instead of
     * an activation failure. Core offers the integration point; it does not require
     * the integration to exist.
     *
     * Idempotent on (menu, locale, url): re-activating a module does not stack up
     * duplicate links, and an operator who renamed the label keeps their label.
     *
     * @param string $menuIdentifier e.g. "header", "footer"
     * @param string $url            absolute path, e.g. "/tr/showcase"
     *
     * @return bool true when a row was created
     */
    public function ensureMenuLink(string $menuIdentifier, string $label, string $url, string $locale, int $sortOrder = 50): bool
    {
        $label = trim($label);
        $url = trim($url);

        if ($label === '' || $url === '' || !str_starts_with($url, '/')) {
            return false;
        }

        try {
            if (!$this->tableExists('cp_menu_menus') || !$this->tableExists('cp_menu_items')) {
                return false;
            }

            $menuId = $this->connection->fetchOne(
                'SELECT id FROM cp_menu_menus WHERE identifier = :identifier LIMIT 1',
                ['identifier' => $menuIdentifier],
            );

            if ($menuId === false || $menuId === null) {
                return false;
            }

            $existing = $this->connection->fetchOne(
                'SELECT id FROM cp_menu_items WHERE menu_id = :menu AND locale = :locale AND url = :url LIMIT 1',
                ['menu' => (int) $menuId, 'locale' => $locale, 'url' => $url],
            );

            if ($existing !== false && $existing !== null) {
                return false;
            }

            $this->connection->executeStatement(
                'INSERT INTO cp_menu_items (menu_id, label, locale, url, sort_order, open_in_new_tab)
                 VALUES (:menu, :label, :locale, :url, :sortOrder, 0)',
                [
                    'menu' => (int) $menuId,
                    'label' => mb_substr($label, 0, 191),
                    'locale' => $locale,
                    'url' => mb_substr($url, 0, 255),
                    'sortOrder' => $sortOrder,
                ],
            );

            return true;
        } catch (\Throwable) {
            // A missing menu link is a cosmetic loss; activation must not fail over it.
            return false;
        }
    }

    /**
     * Removes menu links this module published. Matched on URL prefix so every
     * locale variant of the same entry point goes at once, and so a link the
     * operator renamed is still recognised as belonging to this module.
     *
     * Only ever called from uninstall(); a plain deactivation leaves the site's
     * navigation alone.
     */
    public function removeMenuLinks(string $urlPrefix): int
    {
        $urlPrefix = trim($urlPrefix);

        if ($urlPrefix === '' || !str_starts_with($urlPrefix, '/')) {
            return 0;
        }

        try {
            if (!$this->tableExists('cp_menu_items')) {
                return 0;
            }

            // LIKE wildcards in the prefix would widen the delete well past this
            // module's own links, so they are escaped before it is used.
            $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $urlPrefix).'%';

            return (int) $this->connection->executeStatement(
                'DELETE FROM cp_menu_items WHERE url = :exact OR url LIKE :pattern',
                ['exact' => $urlPrefix, 'pattern' => $pattern],
            );
        } catch (\Throwable) {
            return 0;
        }
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
