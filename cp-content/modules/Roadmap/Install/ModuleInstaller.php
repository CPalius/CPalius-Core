<?php

declare(strict_types=1);

namespace Modules\Roadmap\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'roadmap';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_roadmap_entries',
        ];
    }
}
