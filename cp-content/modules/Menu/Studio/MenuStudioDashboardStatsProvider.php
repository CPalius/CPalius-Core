<?php

declare(strict_types=1);

namespace Modules\Menu\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use Modules\Menu\Entity\Menu;
use Modules\Menu\Entity\MenuItem;
use Doctrine\ORM\EntityManagerInterface;

final class MenuStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getKey(): string
    {
        return 'menu';
    }

    public function getLabel(): string
    {
        return 'Menü';
    }

    public function getIcon(): string
    {
        return 'heroicons:bars-3';
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getRoutePrefixes(): array
    {
        return ['admin_menus_'];
    }

    public function getWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'menu.summary', 'titleKey' => 'studio.dashboard.chart.menu_summary'],
            ['widgetId' => 'menu.links', 'titleKey' => 'studio.dashboard.widget.quick_links'],
        ];
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $menus = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Menu::class, 'm')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(MenuItem::class, 'i')
            ->getQuery()
            ->getSingleScalarResult();

        return new StudioDashboardContribution(
            heroMetrics: [
                ['key' => 'menu_items', 'labelKey' => 'studio.dashboard.kind.menu_items', 'value' => $items],
            ],
            mixItems: [
                ['key' => 'menus', 'labelKey' => 'studio.dashboard.kind.menus', 'count' => $menus],
                ['key' => 'menu_items', 'labelKey' => 'studio.dashboard.kind.menu_items', 'count' => $items],
            ],
            radarItems: [
                ['labelKey' => 'studio.dashboard.kind.menu_items', 'value' => $items],
            ],
            panels: [
                [
                    'widgetId' => 'menu.summary',
                    'layout' => 'tall',
                    'titleKey' => 'studio.dashboard.chart.menu_summary',
                    'chart' => [
                        'type' => 'doughnut-center',
                        'center' => $menus,
                        'centerLabelKey' => 'studio.dashboard.kind.menus',
                        'labelKeys' => [
                            'studio.dashboard.kind.menus',
                            'studio.dashboard.kind.menu_items',
                        ],
                        'values' => [$menus, $items],
                    ],
                ],
                [
                    'widgetId' => 'menu.links',
                    'layout' => 'wide',
                    'titleKey' => 'studio.dashboard.widget.quick_links',
                    'links' => true,
                ],
            ],
            links: [
                ['label' => 'studio.menu.menu_management', 'icon' => 'heroicons:bars-3', 'routeName' => 'admin_menus_index'],
            ],
        );
    }
}
