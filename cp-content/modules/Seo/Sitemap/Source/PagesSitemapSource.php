<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap\Source;

use App\Core\Localization\LocaleProvider;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapUrl;

final class PagesSitemapSource implements SeoSitemapSourceInterface
{
    public function __construct(
        private readonly SeoUrlBuilder $urls,
        private readonly LocaleProvider $locales,
    ) {
    }

    public function name(): string
    {
        return 'pages';
    }

    public function urls(string $locale): iterable
    {
        $routes = [
            'theme_cpalius_website_home' => ['changefreq' => 'daily', 'priority' => '1.0'],
            'theme_whitepaper' => ['changefreq' => 'monthly', 'priority' => '0.6'],
            'blog_index' => ['changefreq' => 'daily', 'priority' => '0.8'],
            'forum_index' => ['changefreq' => 'hourly', 'priority' => '0.8'],
            'roadmap_index' => ['changefreq' => 'weekly', 'priority' => '0.7'],
        ];

        foreach ($routes as $route => $meta) {
            try {
                $alternates = [];
                foreach ($this->locales->getCodes() as $code) {
                    $alternates[$code] = $this->urls->absolute($route, ['_locale' => $code], $code);
                }
                yield new SitemapUrl(
                    loc: $this->urls->absolute($route, ['_locale' => $locale], $locale),
                    changefreq: $meta['changefreq'],
                    priority: $meta['priority'],
                    alternates: $alternates,
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }
}
