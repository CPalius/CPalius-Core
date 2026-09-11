<?php

declare(strict_types=1);

namespace Modules\Roadmap\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Localization\LocaleProvider;
use Modules\Roadmap\Repository\RoadmapEntryRepository;

/**
 * Supplies Roadmap mix counts to the Studio command desk.
 */
final class RoadmapStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly RoadmapEntryRepository $entryRepository,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function getKey(): string
    {
        return 'roadmap';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.roadmap';
    }

    public function getIcon(): string
    {
        return 'heroicons:map';
    }

    public function getPriority(): int
    {
        return 24;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $total = \count($this->entryRepository->findAdminList($this->localeProvider->getDefaultCode()));

        return new StudioDashboardContribution(
            mixItems: [
                ['key' => 'roadmap', 'labelKey' => 'studio.dashboard.kind.roadmap', 'count' => $total],
            ],
        );
    }
}
