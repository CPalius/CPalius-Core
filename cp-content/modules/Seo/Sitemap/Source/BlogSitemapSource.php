<?php

declare(strict_types=1);

namespace Modules\Seo\Sitemap\Source;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Asset;
use App\Entity\Node;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Modules\Seo\Contract\SeoSitemapSourceInterface;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Sitemap\SitemapUrl;
use Symfony\Component\Uid\Uuid;

final class BlogSitemapSource implements SeoSitemapSourceInterface
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AssetRepository $assets,
        private readonly SeoUrlBuilder $urls,
        private readonly SettingsRegistry $settings,
    ) {
    }

    public function name(): string
    {
        return 'blog';
    }

    public function urls(string $locale): iterable
    {
        foreach ($this->categories->findTreeByLocale($locale) as $category) {
            yield new SitemapUrl(
                loc: $this->urls->absolute('blog_category', ['_locale' => $locale, 'slug' => $category->getSlug()], $locale),
                changefreq: 'weekly',
                priority: '0.5',
            );
        }

        foreach ($this->tags->findByLocale($locale) as $tag) {
            yield new SitemapUrl(
                loc: $this->urls->absolute('blog_tag', ['_locale' => $locale, 'slug' => $tag->getSlug()], $locale),
                changefreq: 'weekly',
                priority: '0.4',
            );
        }

        $offset = 0;
        do {
            $batch = $this->nodes->findPublishedByTypeAndLocale('post', $locale, 200, $offset);
            foreach ($batch as $node) {
                yield $this->postUrl($node, $locale);
            }
            $offset += 200;
        } while (\count($batch) === 200);
    }

    private function postUrl(Node $node, string $locale): SitemapUrl
    {
        $alternates = [];
        $groupId = $node->getTranslationGroupId();
        if ($groupId instanceof Uuid) {
            foreach ($this->nodes->findTranslations($groupId) as $translation) {
                $alternates[$translation->getLocale()] = $this->urls->absolute('blog_show', [
                    '_locale' => $translation->getLocale(),
                    'slug' => $translation->getSlug(),
                ], $translation->getLocale());
            }
        }

        $images = [];
        $videoUrl = null;
        $videoTitle = null;
        if ($this->isOn('seo.sitemap_include_images')) {
            $assetId = $node->getDataValue('featured_image_asset_id');
            if (is_numeric($assetId)) {
                $asset = $this->assets->find((int) $assetId);
                if ($asset instanceof Asset) {
                    $images[] = $this->urls->assetUrl($asset->getStorageKey(), $locale);
                }
            }
        }
        if ($this->isOn('seo.sitemap_include_videos')) {
            $fields = $node->getDataValue('type_fields', []);
            $candidate = \is_array($fields) ? (string) ($fields['demo_url'] ?? '') : '';
            if ($candidate !== '' && preg_match('/(youtube\\.com|youtu\\.be|vimeo\\.com|\\.(mp4|webm)(\\?|$))/i', $candidate) === 1) {
                $videoUrl = $candidate;
                $videoTitle = $node->getTitle();
            }
        }

        return new SitemapUrl(
            loc: $this->urls->absolute('blog_show', ['_locale' => $locale, 'slug' => $node->getSlug()], $locale),
            lastmod: $node->getUpdatedAt(),
            changefreq: 'weekly',
            priority: '0.8',
            alternates: $alternates,
            images: $images,
            videoUrl: $videoUrl,
            videoTitle: $videoTitle,
        );
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
