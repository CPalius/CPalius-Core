<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap\Source;

use App\Core\Localization\LocaleProvider;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapUrl;
use Symfony\Component\Uid\Uuid;

final class PagesSitemapSource implements SeoSitemapSourceInterface
{
    public function __construct(
        private readonly SeoUrlBuilder $urls,
        private readonly LocaleProvider $locales,
        private readonly NodeRepository $nodes,
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
            'whitepaper_show' => ['changefreq' => 'monthly', 'priority' => '0.6'],
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

        $offset = 0;
        do {
            $batch = $this->nodes->findPublishedByTypeAndLocale('page', $locale, 200, $offset);
            foreach ($batch as $node) {
                try {
                    yield $this->pageUrl($node, $locale);
                } catch (\Throwable) {
                    continue;
                }
            }
            $offset += 200;
        } while (\count($batch) === 200);
    }

    private function pageUrl(Node $node, string $locale): SitemapUrl
    {
        $alternates = [];
        $groupId = $node->getTranslationGroupId();
        if ($groupId instanceof Uuid) {
            foreach ($this->nodes->findTranslations($groupId) as $translation) {
                if ($translation->getType() !== 'page' || $translation->getStatus() !== Node::STATUS_PUBLISHED) {
                    continue;
                }
                $alternates[$translation->getLocale()] = $this->urls->absolute('page_show', [
                    '_locale' => $translation->getLocale(),
                    'slug' => $translation->getSlug(),
                ], $translation->getLocale());
            }
        }

        return new SitemapUrl(
            loc: $this->urls->absolute('page_show', ['_locale' => $locale, 'slug' => $node->getSlug()], $locale),
            lastmod: $node->getUpdatedAt(),
            changefreq: 'weekly',
            priority: '0.7',
            alternates: $alternates,
        );
    }
}
