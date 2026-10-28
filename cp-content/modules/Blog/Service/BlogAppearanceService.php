<?php

declare(strict_types=1);

namespace Modules\Blog\Service;

use App\Core\Settings\SettingsRegistry;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\NodeFieldIndex;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;

/**
 * Builds hero, featured strip, and stats payloads for blog list pages.
 */
final class BlogAppearanceService
{
    private const NODE_TYPE = 'post';
    private const DEFAULT_FEATURED_LIMIT = 8;
    private const DEFAULT_RELATED_LIMIT = 3;

    /** Must match the variants of blog.related_source. */
    private const RELATED_STRATEGIES = ['same_category', 'same_tags', 'fixed_category', 'latest'];

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly AssetRepository $assetRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
    ) {
    }

    /**
     * @return array{
     *     enabled: bool,
     *     label: string,
     *     title: string,
     *     subtitle: string,
     *     description: string,
     *     ctaPrimaryLabel: string,
     *     ctaPrimaryHref: string,
     *     ctaSecondaryLabel: string,
     *     ctaSecondaryHref: string,
     *     imageUrl: string|null,
     *     showStats: bool
     * }
     */
    public function resolveHero(): array
    {
        $imageUrl = null;
        $assetId = trim((string) $this->settingsRegistry->get('blog.hero_image_asset_id'));
        if ($assetId !== '' && is_numeric($assetId)) {
            $asset = $this->assetRepository->find((int) $assetId);
            if ($asset !== null && $asset->getStorageKey() !== null) {
                $imageUrl = '/uploads/'.$asset->getStorageKey();
            }
        }

        return [
            'enabled' => (bool) $this->settingsRegistry->get('blog.hero_enabled'),
            'label' => (string) $this->settingsRegistry->get('blog.hero_label'),
            'title' => (string) $this->settingsRegistry->get('blog.hero_title'),
            'subtitle' => (string) $this->settingsRegistry->get('blog.hero_subtitle'),
            'description' => (string) $this->settingsRegistry->get('blog.hero_description'),
            'ctaPrimaryLabel' => (string) $this->settingsRegistry->get('blog.hero_cta_primary_label'),
            'ctaPrimaryHref' => (string) $this->settingsRegistry->get('blog.hero_cta_primary_href'),
            'ctaSecondaryLabel' => (string) $this->settingsRegistry->get('blog.hero_cta_secondary_label'),
            'ctaSecondaryHref' => (string) $this->settingsRegistry->get('blog.hero_cta_secondary_href'),
            'imageUrl' => $imageUrl,
            'showStats' => (bool) $this->settingsRegistry->get('blog.hero_show_stats'),
        ];
    }

    /**
     * @return list<Node>
     */
    public function resolveFeaturedPosts(string $locale): array
    {
        if (!(bool) $this->settingsRegistry->get('blog.featured_enabled')) {
            return [];
        }

        $limit = (int) $this->settingsRegistry->get('blog.featured_limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_FEATURED_LIMIT;
        }

        // Featured posts first, then the newest to fill the strip. Two cheap queries instead of one that
        // sorts every published post by a CASE expression: that sort cannot use an index, read all
        // ~5 000 posts of a mid-sized blog (~50 ms) and grows with the archive. The featured set is a
        // handful of rows found through the (field, value) index; the fill is the newest N, which the
        // (type, locale, status, published_at) index answers by reading N rows.
        $poolSize = max($limit * 3, 24);

        /** @var list<Node> $featured */
        $featured = $this->nodeRepository
            ->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE, $locale)
            ->leftJoin('n.author', 'a')
            ->addSelect('a')
            ->innerJoin(
                NodeFieldIndex::class,
                'idxFeat',
                'WITH',
                'idxFeat.node = n AND idxFeat.fieldName = :featField AND idxFeat.valueInt = 1'
            )
            ->setParameter('featField', 'is_featured')
            ->resetDQLPart('orderBy')
            ->addOrderBy('n.publishedAt', 'DESC')
            ->setMaxResults($poolSize)
            ->getQuery()
            ->getResult();

        $candidates = $featured;
        $missing = $limit - \count($featured);
        if ($missing > 0) {
            $qb = $this->nodeRepository
                ->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE, $locale)
                ->leftJoin('n.author', 'a')
                ->addSelect('a')
                ->resetDQLPart('orderBy')
                ->addOrderBy('n.publishedAt', 'DESC')
                ->setMaxResults($missing);
            if ($featured !== []) {
                $qb->andWhere('n.id NOT IN (:featuredIds)')
                    ->setParameter('featuredIds', array_map(static fn (Node $n): ?int => $n->getId(), $featured));
            }
            /** @var list<Node> $fill */
            $fill = $qb->getQuery()->getResult();
            $candidates = [...$featured, ...$fill];
        }

        return array_values(array_slice($candidates, 0, $limit));
    }

    /**
     * Category map for the blog index: roots, children, and post counts.
     *
     * @return array{roots: list<Term>, postCounts: array<int, int>}
     */
    public function resolveCategoryTree(string $locale): array
    {
        return [
            'roots' => $this->categoryRepository->findTreeByLocale($locale),
            'postCounts' => $this->categoryRepository->countPublishedPostsByLocale(self::NODE_TYPE, $locale),
        ];
    }

    /**
     * @return array{posts: int, categories: int, tags: int}
     */
    public function resolveStats(string $locale): array
    {
        $posts = (int) $this->nodeRepository
            ->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE, $locale)
            ->select('COUNT(n.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'posts' => $posts,
            'categories' => $this->categoryRepository->countAll(),
            'tags' => $this->tagRepository->countAll(),
        ];
    }

    public function listLayout(): string
    {
        $layout = (string) $this->settingsRegistry->get('blog.list_layout');

        return in_array($layout, ['grid', 'list'], true) ? $layout : 'grid';
    }

    public function postsPerPage(): int
    {
        $perPage = (int) $this->settingsRegistry->get('blog.default_posts_per_page');

        return $perPage > 0 ? $perPage : 10;
    }

    /**
     * Related-posts block for one post: the heading, and the posts themselves.
     *
     * Resolved here rather than in the template so the controller hands the
     * theme a finished block — a theme that wants to move or restyle it should
     * not have to know which setting decides where the posts come from.
     *
     * @return array{enabled: bool, title: string, posts: list<Node>}
     */
    public function resolveRelatedPosts(Node $post): array
    {
        $empty = ['enabled' => false, 'title' => '', 'posts' => []];

        if (!(bool) $this->settingsRegistry->get('blog.related_enabled')) {
            return $empty;
        }

        $limit = (int) $this->settingsRegistry->get('blog.related_limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_RELATED_LIMIT;
        }

        $strategy = (string) $this->settingsRegistry->get('blog.related_source');
        if (!\in_array($strategy, self::RELATED_STRATEGIES, true)) {
            $strategy = 'same_category';
        }

        $categoryId = trim((string) $this->settingsRegistry->get('blog.related_category'));

        $posts = $this->nodeRepository->findRelatedPosts(
            $post,
            $limit,
            $strategy,
            $categoryId !== '' && ctype_digit($categoryId) ? (int) $categoryId : null,
        );

        if ($posts === []) {
            return $empty;
        }

        return [
            'enabled' => true,
            'title' => trim((string) $this->settingsRegistry->get('blog.related_title')),
            'posts' => $posts,
        ];
    }
}
