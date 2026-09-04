<?php

declare(strict_types=1);

namespace Modules\Blog\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Blog stores posts as core Node rows — uninstall must not drop nodes.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'blog';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }
}
