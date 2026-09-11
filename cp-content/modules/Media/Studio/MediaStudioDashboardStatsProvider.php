<?php

declare(strict_types=1);

namespace Modules\Media\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Repository\AssetRepository;

/**
 * Supplies media disk volume to the Studio command desk.
 */
final class MediaStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function getKey(): string
    {
        return 'media';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.media';
    }

    public function getIcon(): string
    {
        return 'heroicons:photo';
    }

    public function getPriority(): int
    {
        return 25;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        return new StudioDashboardContribution(
            mediaBytes: $this->assetRepository->sumFileSize(),
        );
    }
}
