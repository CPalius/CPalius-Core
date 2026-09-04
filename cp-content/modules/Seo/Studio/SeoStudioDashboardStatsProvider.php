<?php

declare(strict_types=1);

namespace Modules\Seo\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;

final class SeoStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly LocaleProvider $locales,
    ) {
    }

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

    public function getRoutePrefixes(): array
    {
        return ['admin_seo_'];
    }

    public function getWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'seo.links', 'titleKey' => 'studio.dashboard.widget.quick_links'],
        ];
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $locales = \count($this->locales->getCodes());
        $sitemapOn = $this->settings->get('seo.sitemap_enabled', '1') === '1' || $this->settings->get('seo.sitemap_enabled', '1') === true;

        return new StudioDashboardContribution(
            heroMetrics: [
                ['key' => 'seo', 'labelKey' => 'studio.dashboard.kind.seo', 'value' => $locales],
            ],
            mixItems: [
                ['key' => 'seo', 'labelKey' => 'studio.dashboard.kind.seo', 'count' => $sitemapOn ? 1 : 0],
            ],
            panels: [
                [
                    'widgetId' => 'seo.links',
                    'layout' => 'wide',
                    'titleKey' => 'studio.dashboard.widget.quick_links',
                    'links' => true,
                ],
            ],
            links: [
                ['label' => 'studio.dashboard.link.seo', 'icon' => 'heroicons:magnifying-glass-circle', 'routeName' => 'admin_seo_index'],
            ],
        );
    }
}
