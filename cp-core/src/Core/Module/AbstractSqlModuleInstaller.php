<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Default installer: apply Resources/migrations/*.sql on install/upgrade, drop listed tables on uninstall.
 * Historical schema for shipped modules still lives in cp-core/migrations/; new tables go in the module dir.
 */
abstract class AbstractSqlModuleInstaller implements ModuleInstallerInterface
{
    /**
     * Table names dropped on uninstall, children first (FK order). Empty = settings-only module.
     *
     * @return list<string>
     */
    abstract protected function tables(): array;

    /**
     * cp_settings.module id used by purgeSettings() (usually the directory name, lowercased).
     */
    abstract protected function moduleId(): string;

    public function install(ModuleInstallContext $context): void
    {
        $context->applyPendingSqlMigrations();
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        $context->applyPendingSqlMigrations();
    }

    public function uninstall(ModuleInstallContext $context): void
    {
        foreach ($this->tables() as $table) {
            $context->dropTableIfExists($table);
        }

        $context->purgeSettings($this->moduleId());
        $context->forgetSqlMigrationState();
    }
}
