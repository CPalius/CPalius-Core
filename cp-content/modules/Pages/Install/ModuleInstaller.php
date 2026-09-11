<?php

declare(strict_types=1);

namespace Modules\Pages\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Pages are core Node rows (type=page). Field groups are Node type page_field_group.
 * No extra tables; uninstall(--purge) does not drop Node rows.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'pages';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }
}
