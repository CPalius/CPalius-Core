<?php

declare(strict_types=1);

namespace Modules\Media\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Assets live in the core Asset table — uninstall only purges media settings.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'media';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }
}
