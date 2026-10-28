<?php

declare(strict_types=1);

namespace Modules\Media\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Assets live in the core Asset table — uninstall drops only the library's own
 * tables (folders, placements, favourites) and purges media settings. Files and
 * Asset rows stay: core must keep working without this module (Law 2).
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
        return ['cp_media_user_flags', 'cp_media_placements', 'cp_media_folders'];
    }
}
