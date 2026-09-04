<?php

declare(strict_types=1);

namespace Modules\Blog\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Security\QueryScopeApplier;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;

/**
 * Supplies Blog content stats to the Studio overview via tagged iterator.
 */
final class BlogStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
    ) {
    }

    public function getKey(): string
    {
        return 'blog';
    }

    public function getLabel(): string
    {
        return 'Blog';
    }

    public function getIcon(): string
    {
        return 'heroicons:document-text';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getRoutePrefixes(): array
    {
        return ['admin_posts_', 'admin_categories_', 'admin_tags_', 'admin_blog_'];
    }

    public function getWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'blog.publish_gauge', 'titleKey' => 'studio.dashboard.chart.blog_publish_rate'],
            ['widgetId' => 'blog.radar', 'titleKey' => 'studio.dashboard.chart.blog_radar'],
            ['widgetId' => 'blog.links', 'titleKey' => 'studio.dashboard.widget.quick_links'],
        ];
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $byStatus = $this->scopedPostCountsByStatus();
        $total = array_sum(array_column($byStatus, 'count'));
        $published = $this->sumStatus($byStatus, 'published');
        $draft = $this->sumStatus($byStatus, 'draft');
        $scheduled = $this->sumStatus($byStatus, 'scheduled');
        $categories = $this->categoryRepository->countAll();
        $tags = $this->tagRepository->countAll();
        $publishRate = $total > 0 ? (int) round(($published / $total) * 100) : 0;

        $radar = [
            ['labelKey' => 'studio.dashboard.published', 'value' => $published],
            ['labelKey' => 'studio.dashboard.draft', 'value' => $draft],
            ['labelKey' => 'studio.dashboard.blog.scheduled', 'value' => $scheduled],
            ['labelKey' => 'studio.dashboard.blog.categories', 'value' => $categories],
            ['labelKey' => 'studio.dashboard.blog.tags', 'value' => $tags],
        ];

        return new StudioDashboardContribution(
            heroMetrics: [
                ['key' => 'blog_posts', 'labelKey' => 'studio.dashboard.kind.blog_posts', 'value' => $total],
            ],
            mixItems: [
                ['key' => 'blog_posts', 'labelKey' => 'studio.dashboard.kind.blog_posts', 'count' => $total],
                ['key' => 'categories', 'labelKey' => 'studio.dashboard.kind.categories', 'count' => $categories],
                ['key' => 'tags', 'labelKey' => 'studio.dashboard.kind.tags', 'count' => $tags],
            ],
            trendSeries: [
                ['labelKey' => 'studio.dashboard.kind.blog_posts', 'values' => $this->countPostsByMonth()],
            ],
            radarItems: [
                ['labelKey' => 'studio.dashboard.kind.blog_posts', 'value' => $total],
                ['labelKey' => 'studio.dashboard.kind.categories', 'value' => $categories],
                ['labelKey' => 'studio.dashboard.kind.tags', 'value' => $tags],
            ],
            statusBreakdown: $byStatus,
            panels: [
                [
                    'widgetId' => 'blog.publish_gauge',
                    'layout' => 'tall',
                    'titleKey' => 'studio.dashboard.chart.blog_publish_rate',
                    'chart' => [
                        'type' => 'gauge',
                        'value' => $publishRate,
                        'max' => 100,
                        'center' => $publishRate.'%',
                        'centerLabelKey' => 'studio.dashboard.published',
                    ],
                ],
                [
                    'widgetId' => 'blog.radar',
                    'layout' => 'tall',
                    'titleKey' => 'studio.dashboard.chart.blog_radar',
                    'chart' => [
                        'type' => 'radar',
                        'labelKeys' => array_column($radar, 'labelKey'),
                        'values' => array_column($radar, 'value'),
                    ],
                ],
                [
                    'widgetId' => 'blog.links',
                    'layout' => 'wide',
                    'titleKey' => 'studio.dashboard.widget.quick_links',
                    'links' => true,
                ],
            ],
            links: [
                ['label' => 'studio.dashboard.link.blog_posts', 'icon' => 'heroicons:document-text', 'routeName' => 'admin_posts_index'],
                ['label' => 'studio.dashboard.link.categories', 'icon' => 'heroicons:folder', 'routeName' => 'admin_categories_index'],
                ['label' => 'studio.dashboard.link.tags', 'icon' => 'heroicons:tag', 'routeName' => 'admin_tags_index'],
            ],
            publishRate: $publishRate,
        );
    }

    /**
     * @return list<array{status: string, count: int}>
     */
    private function scopedPostCountsByStatus(): array
    {
        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS count')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', self::NODE_TYPE)
            ->groupBy('n.status')
            ->orderBy('count', 'DESC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        return array_map(
            static fn (array $row): array => ['status' => (string) $row['status'], 'count' => (int) $row['count']],
            $qb->getQuery()->getResult(),
        );
    }

    /**
     * @param list<array{status: string, count: int}> $rows
     */
    private function sumStatus(array $rows, string $status): int
    {
        foreach ($rows as $row) {
            if ($row['status'] === $status) {
                return $row['count'];
            }
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    private function countPostsByMonth(): array
    {
        $months = [];
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 5; $i >= 0; --$i) {
            $months[] = $now->modify("-{$i} months");
        }
        $monthKeys = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m'), $months);
        $counts = array_fill_keys($monthKeys, 0);
        $since = $months[0];

        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.createdAt')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.createdAt >= :since')
            ->setParameter('type', self::NODE_TYPE)
            ->setParameter('since', $since)
            ->orderBy('n.createdAt', 'ASC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        foreach ($qb->getQuery()->getResult() as $row) {
            $createdAt = \is_array($row) ? ($row['createdAt'] ?? null) : null;
            if (!$createdAt instanceof \DateTimeInterface) {
                continue;
            }
            $key = $createdAt->format('Y-m');
            if (isset($counts[$key])) {
                ++$counts[$key];
            }
        }

        return array_values($counts);
    }
}
