<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use App\Core\Settings\SettingsRegistry;
use App\Core\Token\TokenContext;
use App\Core\Token\TokenReplacer;
use App\Entity\Asset;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Modules\Blog\Entity\BlogComment;
use Modules\Blog\Repository\BlogCommentRepository;
use Modules\Blog\Service\BlogCommentService;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

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
        private readonly ?BlogCommentService $commentService = null,
        private readonly ?BlogCommentRepository $commentRepository = null,
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
        $schemaType = $this->schemaType($subType);
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

        // The page entity's url/@id stay the canonical post; the demo/repo/version
        // describe the software, so they live on it (inline for SoftwareSourceCode,
        // otherwise as the article's `about`) instead of overwriting `url`.
        $extra = [];
        if ($subType === 'yazilim' || $subType === 'proje') {
            $software = array_filter([
                'codeRepository' => (string) ($typeFields['repo_url'] ?? ''),
                'version' => (string) ($typeFields['version'] ?? ''),
            ]);
            if ($schemaType === 'SoftwareSourceCode') {
                $extra = $software;
            } else {
                $software += array_filter(['url' => (string) ($typeFields['demo_url'] ?? '')]);
                if ($software !== []) {
                    $extra['about'] = ['@type' => 'SoftwareSourceCode', 'name' => $node->getTitle()] + $software;
                }
            }
        }
        $extra += $this->comments($node, $request->query->getInt('cpage', 1), $locale);

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
            authorUrl: $this->profileUrl($author, $locale),
            publishedAt: $node->getPublishedAt(),
            modifiedAt: $node->getUpdatedAt(),
            locale: $locale,
            schemaExtra: $extra,
            forceNoindex: $noindex,
        );
    }

    /**
     * commentCount + the approved comments on this comment page (cpage), replies
     * nested under their parent — the same slice the post renders.
     *
     * @return array<string, mixed>
     */
    private function comments(Node $node, int $page, string $locale): array
    {
        if ($this->commentService === null || $this->commentRepository === null || !$this->commentService->enabledForPost($node)) {
            return [];
        }

        $count = $this->commentRepository->countApprovedForNode($node);
        if ($count === 0) {
            return ['commentCount' => 0];
        }

        $perPage = $this->commentService->perPage();
        /** @var list<BlogComment> $parents */
        $parents = $this->commentRepository->createApprovedTopLevelQueryBuilder($node)
            ->setFirstResult((max(1, $page) - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $replies = [];
        $parentIds = array_values(array_filter(array_map(static fn (BlogComment $c): ?int => $c->getId(), $parents)));
        foreach ($this->commentRepository->findApprovedRepliesForParents($parentIds) as $reply) {
            $replies[(int) $reply->getParent()?->getId()][] = $this->comment($reply, $locale);
        }

        $comments = [];
        foreach ($parents as $parent) {
            $item = $this->comment($parent, $locale);
            if (isset($replies[(int) $parent->getId()])) {
                $item['comment'] = $replies[(int) $parent->getId()];
            }
            $comments[] = $item;
        }

        return ['commentCount' => $count, 'comment' => $comments];
    }

    /**
     * @return array<string, mixed>
     */
    private function comment(BlogComment $comment, string $locale): array
    {
        $author = ['@type' => 'Person', 'name' => $comment->getDisplayName()];
        $url = $this->profileUrl($comment->getAuthor(), $locale);
        if ($url !== null) {
            $author['url'] = $url;
        }

        return [
            '@type' => 'Comment',
            'text' => trim(html_entity_decode(strip_tags($comment->getBody()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')),
            'author' => $author,
            'datePublished' => $comment->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * The member's public profile; the forum profile is the only one there is, so
     * without the Forum module the author simply carries no url.
     */
    private function profileUrl(?User $user, string $locale): ?string
    {
        $slug = $user?->getProfileSlug() ?? '';
        if ($slug === '') {
            return null;
        }

        try {
            return $this->urls->absolute('forum_profile', ['_locale' => $locale, 'username' => $slug], $locale);
        } catch (RouteNotFoundException) {
            return null;
        }
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

    /**
     * Software posts are articles about software: Google rejects a
     * SoftwareApplication/Product/WebApplication without offers + a rating,
     * which a blog post never has, so they render as the article type.
     */
    private function schemaType(string $subType): string
    {
        return match ($subType) {
            'proje' => (string) $this->settings->get('seo.blog.project_schema', 'SoftwareSourceCode'),
            default => (string) $this->settings->get('seo.blog.article_schema', 'BlogPosting'),
        };
    }

    private function isOn(string $key): bool
    {
        $value = $this->settings->get($key, '1');

        return $value === true || $value === 1 || $value === '1';
    }
}
