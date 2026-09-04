<?php

declare(strict_types=1);

namespace Modules\Widget\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'widget';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }
}
