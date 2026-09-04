<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap;

use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingsRegistry;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Sitemap\Source\BlogSitemapSource;
use Modules\Seo\Sitemap\Source\ForumSitemapSource;
use Modules\Seo\Sitemap\Source\PagesSitemapSource;
use Modules\Seo\Sitemap\Source\RoadmapSitemapSource;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

final class SitemapBuilder
{
    /** @var list<SeoSitemapSourceInterface> */
    private readonly array $sources;

    public function __construct(
        PagesSitemapSource $pages,
        BlogSitemapSource $blog,
        ForumSitemapSource $forum,
        RoadmapSitemapSource $roadmap,
        private readonly LocaleProvider $locales,
        private readonly SettingsRegistry $settings,
        private readonly CacheInterface $cache,
        private readonly SitemapXmlRenderer $xml,
    ) {
        $this->sources = [$pages, $blog, $forum, $roadmap];
    }

    public function indexXml(string $indexLocPrefix): string
    {
        $xml = $this->cache->get('cpalius.seo.sitemap.index', function (ItemInterface $item) use ($indexLocPrefix): string {
            $item->expiresAfter(3600);

            return $this->buildIndex($indexLocPrefix);
        });

        // Empty indexes are not cached as success: a first compile miss must not stick for an hour.
        if (!str_contains($xml, '<sitemap>')) {
            $this->deleteKey('cpalius.seo.sitemap.index');

            return $this->buildIndex($indexLocPrefix);
        }

        return $xml;
    }

    public function sourceXml(string $name, string $locale): string
    {
        $key = 'cpalius.seo.sitemap.'.$name.'.'.$locale;

        return $this->cache->get($key, function (ItemInterface $item) use ($name, $locale): string {
            $item->expiresAfter(3600);
            foreach ($this->sources as $source) {
                if ($source->name() !== $name || !$this->sourceEnabled($name)) {
                    continue;
                }

                return $this->xml->urlset($source->urls($locale));
            }

            return $this->xml->urlset([]);
        });
    }

    public function clearCache(): void
    {
        $this->deleteKey('cpalius.seo.sitemap.index');
        foreach ($this->locales->getCodes() as $locale) {
            foreach ($this->sources as $source) {
                $this->deleteKey('cpalius.seo.sitemap.'.$source->name().'.'.$locale);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function sourceNames(): array
    {
        $names = [];
        foreach ($this->sources as $source) {
            if ($this->sourceEnabled($source->name())) {
                $names[] = $source->name();
            }
        }

        return $names;
    }

    private function buildIndex(string $indexLocPrefix): string
    {
        $entries = [];
        $lastmod = (new \DateTimeImmutable())->format('Y-m-d');
        foreach ($this->locales->getCodes() as $locale) {
            foreach ($this->sources as $source) {
                if (!$this->sourceEnabled($source->name())) {
                    continue;
                }
                $entries[] = [
                    'loc' => rtrim($indexLocPrefix, '/').'/sitemap-'.$source->name().'-'.$locale.'.xml',
                    'lastmod' => $lastmod,
                ];
            }
        }

        return $this->xml->index($entries);
    }

    private function deleteKey(string $key): void
    {
        try {
            if ($this->cache instanceof CacheItemPoolInterface) {
                $this->cache->deleteItem($key);
            }
        } catch (Throwable) {
            // Stale sitemap lasts at most TTL.
        }
    }

    private function sourceEnabled(string $name): bool
    {
        if (!$this->isOn('seo.sitemap_enabled')) {
            return false;
        }
        if ($name === 'forum' && !$this->isOn('seo.sitemap_include_forum')) {
            return false;
        }

        return true;
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
