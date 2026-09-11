<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Entity\Asset;
use App\Entity\Node;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use App\Core\Settings\SettingsRegistry;
use App\Core\Token\TokenContext;
use App\Core\Token\TokenReplacer;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final class BlogSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AssetRepository $assets,
        private readonly SettingsRegistry $settings,
        private readonly SeoUrlBuilder $urls,
        private readonly TokenReplacer $tokenReplacer,
    ) {
    }

    public function priority(): int
    {
        return 60;
    }

    public function supports(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');

        return str_starts_with($route, 'blog_');
    }

    public function document(Request $request): ?SeoDocument
    {
        $route = (string) $request->attributes->get('_route', '');
        $locale = (string) $request->getLocale();

        return match ($route) {
            'blog_show' => $this->post($request, $locale),
            'blog_category' => $this->category($request, $locale),
            'blog_tag' => $this->tag($request, $locale),
            'blog_archive_month' => $this->archive($request, $locale),
            'blog_index' => $this->listing('Blog', $this->urls->absolute('blog_index', ['_locale' => $locale], $locale), $locale),
            default => null,
        };
    }

    private function post(Request $request, string $locale): ?SeoDocument
    {
        $slug = (string) $request->attributes->get('slug', '');
        $node = $this->nodes->findOnePublishedBySlugAndLocale($slug, $locale);
        if (!$node instanceof Node) {
            return null;
        }

        $seo = $node->getDataValue('seo', []);
        $seo = \is_array($seo) ? $seo : [];
        $subType = (string) $node->getDataValue('post_sub_type', 'makale');
        $typeFields = $node->getDataValue('type_fields', []);
        $typeFields = \is_array($typeFields) ? $typeFields : [];

        $description = trim((string) ($seo['meta_description'] ?? ''));
        if ($description === '') {
            $description = trim(strip_tags((string) $node->getDataValue('excerpt', '')));
        }
        if ($description === '') {
            // T2.4: the fallback setting may itself contain [node:...]/[site:...]
            // tokens (e.g. "[node:title] — CPalius blog'unda en son gelişmeler.").
            $description = $this->tokenReplacer->replace(
                (string) $this->settings->getForLocale('blog.meta_description_fallback', $locale, ''),
                TokenContext::for($node),
                true,
            );
        }

        $images = $this->images($node, $seo, $locale);
        $videoUrl = $this->videoUrl($typeFields);
        $schemaType = $this->schemaType($subType, null);
        $kind = $videoUrl !== null ? 'video' : 'blog';

        $canonical = !empty($seo['canonical_url'])
            ? (string) $seo['canonical_url']
            : $this->urls->absolute('blog_show', ['_locale' => $locale, 'slug' => $node->getSlug()], $locale);

        $noindex = !empty($seo['noindex']);
        $author = $node->getAuthor();
        $authorName = $author?->getPublicDisplayName();
        if ($authorName === '') {
            $authorName = null;
        }

        $extra = [];
        if ($subType === 'yazilim' || $subType === 'proje') {
            if (!empty($typeFields['demo_url'])) {
                $extra['url'] = (string) $typeFields['demo_url'];
            }
            if (!empty($typeFields['repo_url'])) {
                $extra['codeRepository'] = (string) $typeFields['repo_url'];
            }
        }

        return new SeoDocument(
            headline: $node->getTitle(),
            description: $description,
            canonicalPath: $canonical,
            ogType: 'article',
            contentKind: $kind,
            schemaType: $schemaType,
            robots: $noindex ? 'noindex, follow' : 'index, follow',
            breadcrumbs: [
                ['name' => 'Blog', 'url' => $this->urls->absolute('blog_index', ['_locale' => $locale], $locale)],
            ],
            images: $images,
            videoUrl: $videoUrl,
            videoTitle: $videoUrl !== null ? $node->getTitle() : null,
            authorName: \is_string($authorName) ? $authorName : null,
            publishedAt: $node->getPublishedAt(),
            modifiedAt: $node->getUpdatedAt(),
            locale: $locale,
            schemaExtra: $extra,
            forceNoindex: $noindex,
        );
    }

    private function category(Request $request, string $locale): ?SeoDocument
    {
        $slug = (string) $request->attributes->get('slug', '');
        $category = $this->categories->findOneBySlug($slug, $locale);
        if ($category === null) {
            return null;
        }

        $url = $this->urls->absolute('blog_category', ['_locale' => $locale, 'slug' => $category->getSlug()], $locale);

        return $this->listing($category->getName(), $url, $locale, 'CollectionPage');
    }

    private function tag(Request $request, string $locale): ?SeoDocument
    {
        $slug = (string) $request->attributes->get('slug', '');
        $tag = $this->tags->findOneBySlug($slug, $locale);
        if ($tag === null) {
            return null;
        }

        $url = $this->urls->absolute('blog_tag', ['_locale' => $locale, 'slug' => $tag->getSlug()], $locale);

        return $this->listing($tag->getName(), $url, $locale, 'CollectionPage');
    }

    private function archive(Request $request, string $locale): SeoDocument
    {
        $year = (string) $request->attributes->get('year', '');
        $month = (string) $request->attributes->get('month', '');
        $url = $this->urls->absolute('blog_archive_month', [
            '_locale' => $locale,
            'year' => $year,
            'month' => $month,
        ], $locale);

        return $this->listing($year.'/'.$month, $url, $locale, 'CollectionPage');
    }

    private function listing(string $headline, string $url, string $locale, string $schemaType = 'CollectionPage'): SeoDocument
    {
        $indexListings = $this->isOn('seo.index_blog_listings');

        return new SeoDocument(
            headline: $headline,
            description: $this->tokenReplacer->replace(
                (string) $this->settings->getForLocale('blog.meta_description_fallback', $locale, ''),
                [],
                true,
            ),
            canonicalPath: $url,
            ogType: 'website',
            contentKind: 'blog',
            schemaType: $schemaType,
            robots: $indexListings ? 'index, follow' : 'noindex, follow',
            breadcrumbs: [
                ['name' => 'Blog', 'url' => $this->urls->absolute('blog_index', ['_locale' => $locale], $locale)],
            ],
            locale: $locale,
            forceNoindex: !$indexListings,
        );
    }

    /**
     * @param array<string, mixed> $seo
     *
     * @return list<string>
     */
    private function images(Node $node, array $seo, string $locale): array
    {
        $ids = [];
        foreach (['og_image_asset_id', 'featured_image_asset_id'] as $key) {
            $raw = $seo[$key] ?? $node->getDataValue($key);
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }
        $featured = $node->getDataValue('featured_image_asset_id');
        if (is_numeric($featured)) {
            $ids[] = (int) $featured;
        }

        $urls = [];
        foreach (array_unique($ids) as $id) {
            $asset = $this->assets->find($id);
            if ($asset instanceof Asset) {
                $urls[] = $this->urls->assetUrl($asset->getStorageKey(), $locale);
            }
        }

        return array_values($urls);
    }

    /**
     * @param array<string, mixed> $typeFields
     */
    private function videoUrl(array $typeFields): ?string
    {
        $candidate = (string) ($typeFields['demo_url'] ?? $typeFields['video_url'] ?? '');
        if ($candidate === '') {
            return null;
        }
        if (preg_match('/(youtube\\.com|youtu\\.be|vimeo\\.com|\\.(mp4|webm|ogg)(\\?|$))/i', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }

    private function schemaType(string $subType, ?string $videoUrl): string
    {
        if ($videoUrl !== null) {
            return 'VideoObject';
        }

        return match ($subType) {
            'proje' => (string) $this->settings->get('seo.blog.project_schema', 'SoftwareSourceCode'),
            'yazilim' => (string) $this->settings->get('seo.blog.software_schema', 'SoftwareApplication'),
            default => (string) $this->settings->get('seo.blog.article_schema', 'BlogPosting'),
        };
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
