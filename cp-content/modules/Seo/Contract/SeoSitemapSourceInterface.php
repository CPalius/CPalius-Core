<?php

declare(strict_types=1);

namespace Modules\Seo\Contract;

use Modules\Seo\Sitemap\SitemapUrl;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('cpalius.seo.sitemap_source')]
interface SeoSitemapSourceInterface
{
    public function name(): string;

    /**
     * @return iterable<SitemapUrl>
     */
    public function urls(string $locale): iterable;
}
