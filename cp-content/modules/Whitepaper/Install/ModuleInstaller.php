<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Install;

use App\Core\Module\AbstractSqlModuleInstaller;

/**
 * Tables are created from Resources/migrations/*.sql and dropped on uninstall.
 *
 * Dropping them is safe in a way it usually is not: the document is exported to
 * cp-content/config/sync/whitepaper.*.yaml, so uninstalling loses the rows and
 * not the work. `cp:config import` puts it back.
 */
final class ModuleInstaller extends AbstractSqlModuleInstaller
{
    protected function moduleId(): string
    {
        return 'whitepaper';
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return [
            'cp_whitepaper_sections',
            'cp_whitepaper_documents',
        ];
    }
}
