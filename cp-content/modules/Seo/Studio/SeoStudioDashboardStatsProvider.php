<?php

declare(strict_types=1);

namespace Modules\Seo\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;

/**
 * SEO module registers on the desk; shortcuts are resolved in core.
 */
final class SeoStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function getKey(): string
    {
        return 'seo';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.seo';
    }

    public function getIcon(): string
    {
        return 'heroicons:magnifying-glass-circle';
    }

    public function getPriority(): int
    {
        return 32;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        return new StudioDashboardContribution();
    }
}
