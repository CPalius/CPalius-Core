<?php

declare(strict_types=1);

namespace App\Core\Admin;

use App\Core\Menu\Twig\AdminMenuRuntime;
use App\Core\Module\ModuleRegistry;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Studio Genel Bakış — yalnızca çekirdek + tagged modül provider'ları.
 * Hiçbir Modules\* sınıfı import edilmez (Core Never Dies).
 */
final class StudioDashboardService
{
    /**
     * @param iterable<StudioDashboardStatsProviderInterface> $statsProviders
     */
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly AdminMenuRuntime $adminMenuRuntime,
        #[TaggedIterator('cpalius.studio.dashboard_stats_provider')]
        private readonly iterable $statsProviders,
    ) {
    }

    /**
     * @return list<array{widgetId: string, titleKey: string, section: string}>
     */
    public function buildWidgetCatalog(): array
    {
        $catalog = [
            ['widgetId' => 'hero.summary', 'titleKey' => 'studio.dashboard.widget.hero', 'section' => 'overview'],
            ['widgetId' => 'content.mix_doughnut', 'titleKey' => 'studio.dashboard.chart.by_kind', 'section' => 'content'],
            ['widgetId' => 'content.mix_bar', 'titleKey' => 'studio.dashboard.chart.by_kind_bar', 'section' => 'content'],
            ['widgetId' => 'content.capacity_gauge', 'titleKey' => 'studio.dashboard.chart.content_capacity', 'section' => 'content'],
            ['widgetId' => 'content.trend_line', 'titleKey' => 'studio.dashboard.chart.trend', 'section' => 'content'],
            ['widgetId' => 'content.status_doughnut', 'titleKey' => 'studio.dashboard.chart.by_status', 'section' => 'content'],
            ['widgetId' => 'modules.radar', 'titleKey' => 'studio.dashboard.chart.module_radar', 'section' => 'modules'],
            ['widgetId' => 'modules.bar', 'titleKey' => 'studio.dashboard.chart.module_overview', 'section' => 'modules'],
        ];

        foreach ($this->sortedProviders() as $provider) {
            try {
                foreach ($provider->getWidgetCatalog() as $entry) {
                    $catalog[] = [
                        'widgetId' => $entry['widgetId'],
                        'titleKey' => $entry['titleKey'],
                        'section' => $provider->getKey(),
                    ];
                }
            } catch (\Throwable) {
                // Fail-safe: bozuk katalog tüm sayfayı düşürmez.
            }
        }

        return $catalog;
    }

    /**
     * @return array{
     *     overview: array{
     *         totalContent: int,
     *         activeModuleCount: int,
     *         publishRate: int,
     *         heroMetrics: list<array{key: string, labelKey: string, value: int|float}>,
     *         publishedContent: int,
     *         draftContent: int,
     *         scheduledContent: int
     *     },
     *     charts: array{
     *         contentMix: list<array{key: string, label: string, count: int}>,
     *         contentByStatus: list<array{status: string, count: int}>,
     *         contentTrend: array{labels: list<string>, series: list<array{label: string, values: list<int>}>},
     *         contentCapacity: array{value: int, max: int, percent: int},
     *         moduleOverview: list<array{label: string, count: int}>,
     *         moduleRadar: list<array{label: string, value: int}>
     *     },
     *     modules: list<array{
     *         key: string,
     *         name: string,
     *         icon: string,
     *         links: list<array{label: string, icon: string, routeName: string}>,
     *         panels: list<array<string, mixed>>,
     *         publishRate: int|null
     *     }>
     * }
     */
    public function build(): array
    {
        $menuTree = $this->adminMenuRuntime->render('studio');
        $providers = $this->sortedProviders();
        $linksByKey = $this->groupMenuLinks($menuTree, $providers);
        $monthMeta = $this->buildMonthMeta();

        $heroMetrics = [];
        $mixItems = [];
        $trendSeries = [];
        $radarItems = [];
        $statusBreakdown = [];
        $modules = [];
        $publishRate = 0;
        $publishedContent = 0;
        $draftContent = 0;
        $scheduledContent = 0;
        $totalContent = 0;

        foreach ($providers as $provider) {
            try {
                $contribution = $provider->buildContribution();
            } catch (\Throwable) {
                continue;
            }

            $key = $provider->getKey();

            foreach ($contribution->heroMetrics as $metric) {
                $heroMetrics[] = $metric;
                $totalContent += (int) $metric['value'];
            }

            foreach ($contribution->mixItems as $item) {
                $mixItems[] = [
                    'key' => $item['key'],
                    'label' => $item['labelKey'],
                    'count' => $item['count'],
                ];
            }

            foreach ($contribution->trendSeries as $series) {
                $values = $series['values'];
                if (\count($values) !== \count($monthMeta['keys'])) {
                    $values = $this->normalizeTrendValues($values, \count($monthMeta['keys']));
                }
                $trendSeries[] = [
                    'label' => $series['labelKey'],
                    'values' => $values,
                ];
            }

            foreach ($contribution->radarItems as $item) {
                $radarItems[] = [
                    'label' => $item['labelKey'],
                    'value' => $item['value'],
                ];
            }

            foreach ($contribution->statusBreakdown as $row) {
                $statusBreakdown[] = $row;
                if ($row['status'] === 'published') {
                    $publishedContent += $row['count'];
                } elseif ($row['status'] === 'draft') {
                    $draftContent += $row['count'];
                } elseif ($row['status'] === 'scheduled') {
                    $scheduledContent += $row['count'];
                }
            }

            if ($contribution->publishRate !== null) {
                $publishRate = $contribution->publishRate;
            }

            $links = $linksByKey[$key] ?? [];
            if ($links === []) {
                $links = $contribution->links;
            }

            $modules[] = [
                'key' => $key,
                'name' => $provider->getLabel(),
                'icon' => $provider->getIcon(),
                'links' => $links,
                'panels' => $contribution->panels,
                'publishRate' => $contribution->publishRate,
            ];
        }

        $heroMetrics[] = [
            'key' => 'active_modules',
            'labelKey' => 'studio.dashboard.overview.active_modules',
            'value' => $this->countActiveModules(),
        ];

        $contentMix = array_values(array_filter(
            $mixItems,
            static fn (array $row): bool => true,
        ));

        $contentByStatus = $this->mergeStatusBreakdown($statusBreakdown);
        if ($contentByStatus === [] && ($publishedContent + $draftContent + $scheduledContent) > 0) {
            foreach ([
                ['status' => 'published', 'count' => $publishedContent],
                ['status' => 'draft', 'count' => $draftContent],
                ['status' => 'scheduled', 'count' => $scheduledContent],
            ] as $row) {
                if ($row['count'] > 0) {
                    $contentByStatus[] = $row;
                }
            }
        }

        $capacityMax = $this->resolveContentCapacityLimit($totalContent);
        $capacityPercent = $capacityMax > 0
            ? min(100, (int) round(($totalContent / $capacityMax) * 100))
            : 0;

        return [
            'overview' => [
                'totalContent' => $totalContent,
                'activeModuleCount' => $this->countActiveModules(),
                'publishRate' => $publishRate,
                'heroMetrics' => $heroMetrics,
                'publishedContent' => $publishedContent,
                'draftContent' => $draftContent,
                'scheduledContent' => $scheduledContent,
            ],
            'charts' => [
                'contentMix' => $contentMix,
                'contentByStatus' => $contentByStatus,
                'contentTrend' => [
                    'labels' => $monthMeta['labels'],
                    'series' => $trendSeries,
                ],
                'contentCapacity' => [
                    'value' => $totalContent,
                    'max' => $capacityMax,
                    'percent' => $capacityPercent,
                ],
                'moduleOverview' => array_map(
                    static fn (array $row): array => ['label' => $row['label'], 'count' => $row['count']],
                    $contentMix,
                ),
                'moduleRadar' => $radarItems,
            ],
            'modules' => $modules,
        ];
    }

    /**
     * İçerik doluluk metresi için yumuşak üst sınır (bir sonraki 250'lik basamak).
     */
    private function resolveContentCapacityLimit(int $totalContent): int
    {
        if ($totalContent <= 0) {
            return 250;
        }

        $step = 250;

        return (int) (ceil($totalContent / $step) * $step + $step);
    }

    /**
     * @return list<StudioDashboardStatsProviderInterface>
     */
    private function sortedProviders(): array
    {
        $providers = [];
        foreach ($this->statsProviders as $provider) {
            if ($provider instanceof StudioDashboardStatsProviderInterface) {
                $providers[] = $provider;
            }
        }

        usort(
            $providers,
            static fn (StudioDashboardStatsProviderInterface $a, StudioDashboardStatsProviderInterface $b): int => $a->getPriority() <=> $b->getPriority(),
        );

        return $providers;
    }

    private function countActiveModules(): int
    {
        return \count(array_filter(
            $this->moduleRegistry->discoverAllModules(),
            static fn (array $m): bool => $m['status'] === 'active' && $m['class'] !== null,
        ));
    }

    /**
     * @return array{keys: list<string>, labels: list<string>}
     */
    private function buildMonthMeta(): array
    {
        $months = [];
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 5; $i >= 0; --$i) {
            $months[] = $now->modify("-{$i} months");
        }

        return [
            'keys' => array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m'), $months),
            'labels' => array_map(static fn (\DateTimeImmutable $d): string => $d->format('M Y'), $months),
        ];
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function normalizeTrendValues(array $values, int $expected): array
    {
        if (\count($values) >= $expected) {
            return \array_slice($values, -$expected);
        }

        return array_pad($values, $expected, 0);
    }

    /**
     * @param list<array{status: string, count: int}> $rows
     * @return list<array{status: string, count: int}>
     */
    private function mergeStatusBreakdown(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            $merged[$status] = ($merged[$status] ?? 0) + $row['count'];
        }

        $out = [];
        foreach ($merged as $status => $count) {
            $out[] = ['status' => $status, 'count' => $count];
        }

        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * @param list<array{label: string, icon: string, routeName: string, routePrefix: string, group: ?string, children: list<array{label: string, icon: string, routeName: string, routePrefix: string}>}> $menuTree
     * @param list<StudioDashboardStatsProviderInterface> $providers
     * @return array<string, list<array{label: string, icon: string, routeName: string}>>
     */
    private function groupMenuLinks(array $menuTree, array $providers): array
    {
        $prefixMap = [];
        foreach ($providers as $provider) {
            foreach ($provider->getRoutePrefixes() as $prefix) {
                $prefixMap[$prefix] = $provider->getKey();
            }
        }

        $grouped = [];

        foreach ($menuTree as $item) {
            if ($item['routeName'] === 'admin_dashboard') {
                continue;
            }

            $key = $this->resolveModuleKey($item['routeName'], $item['routePrefix'], $prefixMap);
            if ($key === null) {
                continue;
            }

            $grouped[$key][] = [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'routeName' => $item['routeName'],
            ];

            foreach ($item['children'] as $child) {
                $childKey = $this->resolveModuleKey($child['routeName'], $child['routePrefix'], $prefixMap) ?? $key;
                $grouped[$childKey][] = [
                    'label' => $child['label'],
                    'icon' => $child['icon'],
                    'routeName' => $child['routeName'],
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, string> $prefixMap prefix => module key
     */
    private function resolveModuleKey(string $routeName, string $routePrefix, array $prefixMap): ?string
    {
        foreach ($prefixMap as $prefix => $key) {
            if (str_starts_with($routeName, $prefix) || str_starts_with($routePrefix, $prefix)) {
                return $key;
            }
        }

        return null;
    }
}
