<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap\Source;

use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapUrl;

final class RoadmapSitemapSource implements SeoSitemapSourceInterface
{
    public function __construct(
        private readonly SeoUrlBuilder $urls,
        private readonly ?RoadmapEntryRepository $entryRepository = null,
    ) {
    }

    public function name(): string
    {
        return 'roadmap';
    }

    public function urls(string $locale): iterable
    {
        if ($this->entryRepository === null) {
            return;
        }

        $entries = $this->entryRepository->createPublicFeedQueryBuilder($locale)
            ->getQuery()
            ->getResult();

        foreach ($entries as $entry) {
            yield new SitemapUrl(
                loc: $this->urls->absolute('roadmap_show', [
                    '_locale' => $locale,
                    'slug' => $entry->getSlug(),
                ], $locale),
                lastmod: $entry->getUpdatedAt(),
                changefreq: 'weekly',
                priority: '0.6',
            );
        }
    }
}
