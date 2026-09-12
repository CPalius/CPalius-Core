<?php

declare(strict_types=1);

namespace Modules\Importer\Install;

use App\Core\Module\AbstractSqlModuleInstaller;
use App\Core\Module\ModuleInstallContext;

/**
 * Nothing to install.
 *
 * The module ships no tables: the import map it relies on is cp_migration_map,
 * which belongs to the core Migrate API and exists whether or not this module
 * is active. That is the point of the split — deactivating the importer must
 * not take the record of what was imported with it.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'importer';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }

    public function install(ModuleInstallContext $context): void
    {
        $context->applyPendingSqlMigrations();
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        $context->applyPendingSqlMigrations();
    }
}
