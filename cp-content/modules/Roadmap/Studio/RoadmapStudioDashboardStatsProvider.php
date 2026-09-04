<?php

declare(strict_types=1);

namespace Modules\Roadmap\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Localization\LocaleProvider;
use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RoadmapStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{


    public function __construct(
        private readonly RoadmapEntryRepository $entryRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getKey(): string
    {
        return 'roadmap';
    }

    public function getLabel(): string
    {
        return 'Yol Haritası';
    }

    public function getIcon(): string
    {
        return 'heroicons:map';
    }

    public function getPriority(): int
    {
        return 24;
    }

    public function getRoutePrefixes(): array
    {
        return ['admin_roadmap_'];
    }

    public function getWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'roadmap.status_pie', 'titleKey' => 'studio.dashboard.chart.roadmap_status'],
            ['widgetId' => 'roadmap.links', 'titleKey' => 'studio.dashboard.widget.quick_links'],
        ];
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $entries = $this->entryRepository->findAdminList($this->localeProvider->getDefaultCode());
        $total = \count($entries);
        $byStatus = [];
        foreach ($entries as $entry) {
            $status = $entry->getStatus();
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $statusRows = [];
        foreach ($byStatus as $status => $count) {
            $statusRows[] = ['status' => $status, 'count' => $count];
        }

        $labels = [];
        $values = [];
        foreach ($statusRows as $row) {
            $labels[] = 'site.roadmap.status.'.$row['status'];
            $values[] = $row['count'];
        }

        return new StudioDashboardContribution(
            heroMetrics: [
                ['key' => 'roadmap', 'labelKey' => 'studio.dashboard.kind.roadmap', 'value' => $total],
            ],
            mixItems: [
                ['key' => 'roadmap', 'labelKey' => 'studio.dashboard.kind.roadmap', 'count' => $total],
            ],
            trendSeries: [
                ['labelKey' => 'studio.dashboard.kind.roadmap', 'values' => $this->countByMonth()],
            ],
            radarItems: [
                ['labelKey' => 'studio.dashboard.kind.roadmap', 'value' => $total],
            ],
            panels: [
                [
                    'widgetId' => 'roadmap.status_pie',
                    'layout' => 'tall',
                    'titleKey' => 'studio.dashboard.chart.roadmap_status',
                    'chart' => [
                        'type' => 'pie',
                        'labelKeys' => $labels,
                        'values' => $values,
                    ],
                ],
                [
                    'widgetId' => 'roadmap.links',
                    'layout' => 'wide',
                    'titleKey' => 'studio.dashboard.widget.quick_links',
                    'links' => true,
                ],
            ],
            links: [
                ['label' => 'studio.roadmap.menu.entries', 'icon' => 'heroicons:map', 'routeName' => 'admin_roadmap_index'],
                ['label' => 'studio.roadmap.menu.settings', 'icon' => 'heroicons:cog-6-tooth', 'routeName' => 'admin_roadmap_settings_index'],
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function countByMonth(): array
    {
        $months = [];
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 5; $i >= 0; --$i) {
            $months[] = $now->modify("-{$i} months");
        }
        $monthKeys = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m'), $months);
        $counts = array_fill_keys($monthKeys, 0);
        $since = $months[0];

        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.createdAt')
            ->from(RoadmapEntry::class, 'e')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $createdAt = \is_array($row) ? ($row['createdAt'] ?? null) : null;
            if (!$createdAt instanceof \DateTimeInterface) {
                continue;
            }
            $key = $createdAt->format('Y-m');
            if (isset($counts[$key])) {
                ++$counts[$key];
            }
        }

        return array_values($counts);
    }
}
