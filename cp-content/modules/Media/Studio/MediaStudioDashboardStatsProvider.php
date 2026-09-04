<?php

declare(strict_types=1);

namespace Modules\Media\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Repository\AssetRepository;

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
        return 'Medya';
    }

    public function getIcon(): string
    {
        return 'heroicons:photo';
    }

    public function getPriority(): int
    {
        return 25;
    }

    public function getRoutePrefixes(): array
    {
        return ['admin_media_'];
    }

    public function getWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'media.mime_pie', 'titleKey' => 'studio.dashboard.chart.media_mime'],
            ['widgetId' => 'media.mime_polar', 'titleKey' => 'studio.dashboard.chart.media_polar'],
            ['widgetId' => 'media.disk_gauge', 'titleKey' => 'studio.dashboard.chart.media_disk'],
            ['widgetId' => 'media.links', 'titleKey' => 'studio.dashboard.widget.quick_links'],
        ];
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $total = $this->assetRepository->countAll();
        $bytes = $this->assetRepository->sumFileSize();
        $byMime = array_map(
            static fn (array $row): array => ['mime' => (string) $row['mimeType'], 'count' => $row['count']],
            $this->assetRepository->countGroupedByMimeType(6),
        );
        $label = $this->formatBytes($bytes);

        $panels = [];
        if ($byMime !== []) {
            $panels[] = [
                'widgetId' => 'media.mime_pie',
                'layout' => 'tall',
                'titleKey' => 'studio.dashboard.chart.media_mime',
                'chart' => [
                    'type' => 'pie',
                    'labels' => array_column($byMime, 'mime'),
                    'values' => array_column($byMime, 'count'),
                ],
            ];
            $panels[] = [
                'widgetId' => 'media.mime_polar',
                'layout' => 'tall',
                'titleKey' => 'studio.dashboard.chart.media_polar',
                'chart' => [
                    'type' => 'polar',
                    'labels' => array_column($byMime, 'mime'),
                    'values' => array_column($byMime, 'count'),
                ],
            ];
        }

        $panels[] = [
            'widgetId' => 'media.disk_gauge',
            'layout' => 'tall',
            'titleKey' => 'studio.dashboard.chart.media_disk',
            'chart' => [
                'type' => 'gauge',
                'value' => $bytes,
                'max' => $bytes > 0 ? (int) round($bytes * 1.25) : 1048576,
                'center' => $label,
                'centerLabelKey' => 'studio.dashboard.media.disk_usage',
            ],
            'footerText' => $total.' files',
            'footerTrans' => [
                'count' => $total,
                'key' => 'studio.dashboard.media.files_label',
            ],
        ];

        $panels[] = [
            'widgetId' => 'media.links',
            'layout' => 'wide',
            'titleKey' => 'studio.dashboard.widget.quick_links',
            'links' => true,
        ];

        return new StudioDashboardContribution(
            heroMetrics: [
                ['key' => 'media', 'labelKey' => 'studio.dashboard.kind.media', 'value' => $total],
            ],
            mixItems: [
                ['key' => 'media', 'labelKey' => 'studio.dashboard.kind.media', 'count' => $total],
            ],
            radarItems: [
                ['labelKey' => 'studio.dashboard.kind.media', 'value' => $total],
            ],
            panels: $panels,
            links: [
                ['label' => 'studio.dashboard.link.media', 'icon' => 'heroicons:photo', 'routeName' => 'admin_media_index'],
            ],
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
