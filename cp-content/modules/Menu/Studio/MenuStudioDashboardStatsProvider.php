<?php

declare(strict_types=1);

namespace Modules\Menu\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;

/**
 * Menu module registers on the desk but does not add chart spam.
 */
final class MenuStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function getKey(): string
    {
        return 'menu';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.menu';
    }

    public function getIcon(): string
    {
        return 'heroicons:bars-3';
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        return new StudioDashboardContribution();
    }
}
