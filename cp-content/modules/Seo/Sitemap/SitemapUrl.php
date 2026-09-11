<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap;

final class SitemapUrl
{
    /**
     * @param array<string, string> $alternates locale => absolute URL
     * @param list<string>          $images     absolute image URLs
     */
    public function __construct(
        public readonly string $loc,
        public readonly ?\DateTimeInterface $lastmod = null,
        public readonly string $changefreq = 'weekly',
        public readonly string $priority = '0.5',
        public readonly array $alternates = [],
        public readonly array $images = [],
        public readonly ?string $videoUrl = null,
        public readonly ?string $videoTitle = null,
    ) {
    }
}
