<?php

declare(strict_types=1);

namespace Modules\Widget\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Creates cp_widgets (Resources/migrations) on install and upgrade. Default widgets are not seeded here:
 * an installer runs without the container and cannot ask which areas the active modules declare, so
 * WidgetManager seeds each area the first time it is drawn or opened in Studio.
 */
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
        return ['cp_widgets'];
    }
}
