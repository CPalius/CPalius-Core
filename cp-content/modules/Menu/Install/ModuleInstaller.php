<?php

declare(strict_types=1);

namespace Modules\Menu\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'menu';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_menu_items',
            'cp_menu_menus',
        ];
    }
}
