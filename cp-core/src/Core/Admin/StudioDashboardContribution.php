<?php

declare(strict_types=1);

namespace App\Core\Admin;

/**
 * Bir modülün Studio dashboard'a gönderdiği salt-veri katkısı.
 * Twig/HTML taşımaz — yalnızca metrikler, grafik payload'ları ve linkler.
 */
final class StudioDashboardContribution
{
    /**
     * @param list<array{key: string, labelKey: string, value: int|float}> $heroMetrics
     * @param list<array{key: string, labelKey: string, count: int}> $mixItems
     * @param list<array{labelKey: string, values: list<int>}> $trendSeries  values = son 6 ay (core ay etiketlerini üretir)
     * @param list<array{labelKey: string, value: int}> $radarItems
     * @param list<array{status: string, count: int}> $statusBreakdown
     * @param list<array{
     *     widgetId: string,
     *     layout: 'tall'|'wide',
     *     titleKey: string,
     *     chart?: array<string, mixed>,
     *     links?: bool,
     *     footerText?: string|null
     * }> $panels
     * @param list<array{label: string, icon: string, routeName: string}> $links
     */
    public function __construct(
        public readonly array $heroMetrics = [],
        public readonly array $mixItems = [],
        public readonly array $trendSeries = [],
        public readonly array $radarItems = [],
        public readonly array $statusBreakdown = [],
        public readonly array $panels = [],
        public readonly array $links = [],
        public readonly ?int $publishRate = null,
    ) {
    }
}
