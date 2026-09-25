<?php

declare(strict_types=1);

namespace Modules\VisitorStats\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'visitorstats';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_visitor_daily_seen_ips',
            'cp_visitor_hourly_stats',
            'cp_visitor_daily_stats',
        ];
    }
}
