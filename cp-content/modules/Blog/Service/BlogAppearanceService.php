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

        // Oversample; prefer is_featured=1 (index join + PHP fallback).
        $poolSize = max($limit * 3, 24);

        /** @var list<Node> $candidates */
        $candidates = $this->nodeRepository
            ->createPublishedByTypeAndLocaleQueryBuilder(self::NODE_TYPE, $locale)
            ->leftJoin('n.author', 'a')
            ->addSelect('a')
            ->addSelect('CASE WHEN idxFeat.valueInt = 1 THEN 0 ELSE 1 END AS HIDDEN featOrder')
            ->leftJoin(
                NodeFieldIndex::class,
                'idxFeat',
                'WITH',
                'idxFeat.node = n AND idxFeat.fieldName = :featField'
            )
            ->setParameter('featField', 'is_featured')
            ->resetDQLPart('orderBy')
            ->addOrderBy('featOrder', 'ASC')
            ->addOrderBy('n.publishedAt', 'DESC')
            ->setMaxResults($poolSize)
            ->getQuery()
            ->getResult();

        usort($candidates, static function (Node $left, Node $right): int {
            $leftFeatured = (int) $left->getDataValue('is_featured', 0) === 1 ? 1 : 0;
            $rightFeatured = (int) $right->getDataValue('is_featured', 0) === 1 ? 1 : 0;
            if ($leftFeatured !== $rightFeatured) {
                return $rightFeatured <=> $leftFeatured;
            }

            $leftTs = $left->getPublishedAt()?->getTimestamp() ?? 0;
            $rightTs = $right->getPublishedAt()?->getTimestamp() ?? 0;

            return $rightTs <=> $leftTs;
        });

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
}
