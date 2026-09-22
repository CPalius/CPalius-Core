<?php

declare(strict_types=1);

namespace Modules\DnsTools\Seo;

use App\Core\Localization\LocaleProvider;
use Modules\DnsTools\Service\ToolRegistry;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapUrl;

final class DnsToolsSitemapSource implements SeoSitemapSourceInterface
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly SeoUrlBuilder $urls,
        private readonly LocaleProvider $locales,
    ) {
    }

    public function name(): string
    {
        return 'dnstools';
    }

    public function urls(string $locale): iterable
    {
        $alternates = $this->alternates('dnstools_index');
        yield new SitemapUrl(
            loc: $this->urls->absolute('dnstools_index', ['_locale' => $locale], $locale),
            changefreq: 'weekly',
            priority: '0.9',
            alternates: $alternates,
        );

        yield new SitemapUrl(
            loc: $this->urls->absolute('dnstools_all', ['_locale' => $locale], $locale),
            changefreq: 'weekly',
            priority: '0.8',
            alternates: $this->alternates('dnstools_all'),
        );

        foreach ($this->registry->all() as $tool) {
            yield new SitemapUrl(
                loc: $this->urls->absolute('dnstools_tool', ['_locale' => $locale, 'slug' => $tool->slug], $locale),
                changefreq: 'monthly',
                priority: $tool->featured ? '0.8' : '0.6',
                alternates: $this->alternates('dnstools_tool', ['slug' => $tool->slug]),
            );
        }
    }

    /**
     * @param array<string, string> $parameters
     * @return array<string, string>
     */
    private function alternates(string $route, array $parameters = []): array
    {
        $out = [];
        foreach ($this->locales->getLocales() as $definition) {
            $code = $definition->code;
            $out[$code] = $this->urls->absolute($route, ['_locale' => $code] + $parameters, $code);
        }

        return $out;
    }
}
