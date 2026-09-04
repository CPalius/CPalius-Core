<?php

declare(strict_types=1);

namespace Modules\Seo\Cron;

use App\Core\Cron\Attribute\CpCronJob;
use Modules\Seo\Sitemap\SitemapBuilder;

final class RebuildSitemapCacheTask
{
    public function __construct(
        private readonly SitemapBuilder $sitemaps,
    ) {
    }

    #[CpCronJob(schedule: '15 * * * *', name: 'seo.rebuild_sitemap', description: 'Warm the SEO sitemap cache')]
    public function execute(): string
    {
        $this->sitemaps->clearCache();

        return 'SEO sitemap cache cleared.';
    }
}
