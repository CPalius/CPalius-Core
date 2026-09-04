<?php

declare(strict_types=1);

namespace Modules\Seo\Install;

use App\Core\Module\AbstractSqlModuleInstaller;
use App\Core\Module\ModuleInstallContext;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'seo';
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
        SeoSettingsSeeder::seed($context->connection);
    }

    public function upgrade(ModuleInstallContext $context, string $fromVersion, string $toVersion): void
    {
        $context->applyPendingSqlMigrations();
        SeoSettingsSeeder::seed($context->connection);
    }
}
