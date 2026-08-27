<?php

declare(strict_types=1);

namespace App\Core\Admin;

use App\Core\Menu\Twig\AdminMenuRuntime;
use App\Core\Module\ModuleRegistry;
use App\Core\Security\QueryScopeApplier;
use App\Entity\ForumSection;
use App\Entity\ForumTopic;
use App\Entity\ForumPost;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\ForumPostReportRepository;
use App\Repository\ForumPostRepository;
use App\Repository\ForumSectionRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Blog\BlogModule;
use Modules\Forum\ForumModule;
use Modules\Media\MediaModule;

/**
 * Studio Genel Bakış ekranının tek veri kaynağı — aktif modüllerin
 * özet istatistiklerini, grafik verilerini ve hızlı erişim linklerini
 * toplar. AACP dashboard'unun sistem odaklı karşılığı; burada yalnızca
 * editörün görebileceği içerik/modül metrikleri yer alır.
 */
final class StudioDashboardService
{
    private const DEFAULT_LOCALE = 'tr';

    /** @var array<string, array{key: string, icon: string, class: class-string, fallbackLinks: list<array{label: string, icon: string, routeName: string}>}> */
    private const STUDIO_MODULES = [
        'Blog' => [
            'key' => 'blog',
            'icon' => 'heroicons:document-text',
            'class' => BlogModule::class,
            'fallbackLinks' => [
                ['label' => 'studio.dashboard.link.blog_posts', 'icon' => 'heroicons:document-text', 'routeName' => 'admin_posts_index'],
                ['label' => 'studio.dashboard.link.categories', 'icon' => 'heroicons:folder', 'routeName' => 'admin_categories_index'],
                ['label' => 'studio.dashboard.link.tags', 'icon' => 'heroicons:tag', 'routeName' => 'admin_tags_index'],
            ],
        ],
        'Forum' => [
            'key' => 'forum',
            'icon' => 'heroicons:chat-bubble-left-right',
            'class' => ForumModule::class,
            'fallbackLinks' => [
                ['label' => 'studio.dashboard.link.forum', 'icon' => 'heroicons:chat-bubble-left-right', 'routeName' => 'admin_forum_dashboard'],
                ['label' => 'studio.dashboard.link.forum_topics', 'icon' => 'heroicons:chat-bubble-left-right', 'routeName' => 'admin_forum_topics_index'],
                ['label' => 'studio.dashboard.link.forum_members', 'icon' => 'heroicons:users', 'routeName' => 'admin_forum_members_index'],
            ],
        ],
        'Media' => [
            'key' => 'media',
            'icon' => 'heroicons:photo',
            'class' => MediaModule::class,
            'fallbackLinks' => [
                ['label' => 'studio.dashboard.link.media', 'icon' => 'heroicons:photo', 'routeName' => 'admin_media_index'],
            ],
        ],
    ];

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly AssetRepository $assetRepository,
        private readonly ForumPostRepository $forumPostRepository,
        private readonly ForumSectionRepository $forumSectionRepository,
        private readonly ForumPostReportRepository $forumPostReportRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly AdminMenuRuntime $adminMenuRuntime,
    ) {
    }

    /**
     * @return list<array{widgetId: string, titleKey: string, section: string}>
     */
    public function buildWidgetCatalog(): array
    {
        $catalog = [
            ['widgetId' => 'hero.summary', 'titleKey' => 'studio.dashboard.widget.hero', 'section' => 'overview'],
            ['widgetId' => 'content.mix_doughnut', 'titleKey' => 'studio.dashboard.chart.by_kind', 'section' => 'content'],
            ['widgetId' => 'content.mix_bar', 'titleKey' => 'studio.dashboard.chart.by_kind_bar', 'section' => 'content'],
            ['widgetId' => 'content.trend_line', 'titleKey' => 'studio.dashboard.chart.trend', 'section' => 'content'],
            ['widgetId' => 'content.status_doughnut', 'titleKey' => 'studio.dashboard.chart.by_status', 'section' => 'content'],
            ['widgetId' => 'modules.radar', 'titleKey' => 'studio.dashboard.chart.module_radar', 'section' => 'modules'],
            ['widgetId' => 'modules.bar', 'titleKey' => 'studio.dashboard.chart.module_overview', 'section' => 'modules'],
        ];

        if ($this->isModuleActive(BlogModule::class)) {
            $catalog[] = ['widgetId' => 'blog.publish_gauge', 'titleKey' => 'studio.dashboard.chart.blog_publish_rate', 'section' => 'blog'];
            $catalog[] = ['widgetId' => 'blog.radar', 'titleKey' => 'studio.dashboard.chart.blog_radar', 'section' => 'blog'];
            $catalog[] = ['widgetId' => 'blog.links', 'titleKey' => 'studio.dashboard.widget.quick_links', 'section' => 'blog'];
        }

        if ($this->isModuleActive(ForumModule::class)) {
            $catalog[] = ['widgetId' => 'forum.moderation_gauge', 'titleKey' => 'studio.dashboard.chart.forum_moderation', 'section' => 'forum'];
            $catalog[] = ['widgetId' => 'forum.section_pie', 'titleKey' => 'studio.dashboard.chart.forum_section_pie', 'section' => 'forum'];
            $catalog[] = ['widgetId' => 'forum.section_bar', 'titleKey' => 'studio.dashboard.chart.forum_section_bar', 'section' => 'forum'];
            $catalog[] = ['widgetId' => 'forum.activity_line', 'titleKey' => 'studio.dashboard.chart.forum_activity', 'section' => 'forum'];
            $catalog[] = ['widgetId' => 'forum.links', 'titleKey' => 'studio.dashboard.widget.quick_links', 'section' => 'forum'];
        }

        if ($this->isModuleActive(MediaModule::class)) {
            $catalog[] = ['widgetId' => 'media.mime_pie', 'titleKey' => 'studio.dashboard.chart.media_mime', 'section' => 'media'];
            $catalog[] = ['widgetId' => 'media.mime_polar', 'titleKey' => 'studio.dashboard.chart.media_polar', 'section' => 'media'];
            $catalog[] = ['widgetId' => 'media.disk_gauge', 'titleKey' => 'studio.dashboard.chart.media_disk', 'section' => 'media'];
            $catalog[] = ['widgetId' => 'media.links', 'titleKey' => 'studio.dashboard.widget.quick_links', 'section' => 'media'];
        }

        return $catalog;
    }

    /**
     * @return array{
     *     overview: array{
     *         totalContent: int,
     *         blogPosts: int,
     *         forumTopics: int,
     *         forumPosts: int,
     *         mediaFiles: int,
     *         publishedContent: int,
     *         draftContent: int,
     *         scheduledContent: int,
     *         activeModuleCount: int,
     *         publishRate: int
     *     },
     *     charts: array<string, mixed>,
     *     modules: list<array{key: string, name: string, icon: string, links: list<array{label: string, icon: string, routeName: string}>}>,
     * }
     */
    public function build(): array
    {
        $activeClasses = $this->activeModuleClasses();
        $menuTree = $this->adminMenuRuntime->render('studio');
        $blogStats = $this->buildBlogStats($activeClasses);
        $forumStats = $this->buildForumStats($activeClasses);
        $mediaStats = $this->buildMediaStats($activeClasses);

        $blogByStatus = $this->scopedPostCountsByStatus();
        $blogTotal = array_sum(array_column($blogByStatus, 'count'));
        $publishedContent = $this->sumStatusCount($blogByStatus, 'published');

        $forumTopics = $forumStats['topics'] ?? 0;
        $forumPosts = $forumStats['posts'] ?? 0;
        $mediaFiles = $mediaStats['total'] ?? 0;

        $contentMix = $this->buildContentMix($blogStats, $forumStats, $mediaStats, $activeClasses);
        $totalContent = ($blogStats['total'] ?? 0)
            + ($forumStats['topics'] ?? 0)
            + ($forumStats['posts'] ?? 0)
            + ($mediaStats['total'] ?? 0);

        return [
            'overview' => [
                'totalContent' => $totalContent,
                'blogPosts' => $blogStats['total'] ?? 0,
                'forumTopics' => $forumTopics,
                'forumPosts' => $forumPosts,
                'mediaFiles' => $mediaFiles,
                'publishedContent' => $publishedContent,
                'draftContent' => $this->sumStatusCount($blogByStatus, 'draft'),
                'scheduledContent' => $this->sumStatusCount($blogByStatus, 'scheduled'),
                'activeModuleCount' => count($activeClasses),
                'publishRate' => $blogTotal > 0 ? (int) round(($publishedContent / $blogTotal) * 100) : 0,
            ],
            'charts' => [
                'contentMix' => $contentMix,
                'contentByStatus' => $blogByStatus,
                'contentTrend' => $this->buildContentTrend($activeClasses),
                'moduleOverview' => $this->buildModuleOverviewChart($blogStats, $forumStats, $mediaStats, $activeClasses),
                'moduleRadar' => $this->buildModuleRadar($blogStats, $forumStats, $mediaStats, $activeClasses),
                'blogRadar' => $this->buildBlogRadar($blogStats),
                'forumSections' => $this->buildForumSectionCharts($activeClasses),
                'forumModeration' => [
                    'open' => $forumStats['openReports'] ?? 0,
                    'total' => $this->isModuleActive(ForumModule::class)
                        ? $this->forumPostReportRepository->countTotal()
                        : 0,
                ],
                'mediaByMimeType' => $mediaStats['byMimeType'] ?? [],
                'mediaDisk' => [
                    'bytes' => $mediaStats['diskUsageBytes'] ?? 0,
                    'label' => $mediaStats['diskUsageLabel'] ?? '0 B',
                    'files' => $mediaStats['total'] ?? 0,
                ],
            ],
            'modules' => $this->buildModuleCards($activeClasses, $menuTree),
        ];
    }

    private function isModuleActive(string $moduleClass): bool
    {
        return in_array($moduleClass, $this->activeModuleClasses(), true);
    }

    /**
     * @return list<string>
     */
    private function activeModuleClasses(): array
    {
        return array_values(array_filter(
            array_column(
                array_filter(
                    $this->moduleRegistry->discoverAllModules(),
                    static fn (array $m): bool => $m['status'] === 'active' && $m['class'] !== null,
                ),
                'class',
            ),
        ));
    }

    /**
     * @param list<string> $activeClasses
     * @return array{total: int, published: int, draft: int, scheduled: int, categories: int, tags: int}|null
     */
    private function buildBlogStats(array $activeClasses): ?array
    {
        if (!in_array(BlogModule::class, $activeClasses, true)) {
            return null;
        }

        $byStatus = $this->scopedPostCountsByStatus();

        return [
            'total' => array_sum(array_column($byStatus, 'count')),
            'published' => $this->sumStatusCount($byStatus, 'published'),
            'draft' => $this->sumStatusCount($byStatus, 'draft'),
            'scheduled' => $this->sumStatusCount($byStatus, 'scheduled'),
            'categories' => $this->categoryRepository->countAll(),
            'tags' => $this->tagRepository->countAll(),
        ];
    }

    /**
     * @param list<string> $activeClasses
     * @return array{topics: int, posts: int, members: int, sections: int, openReports: int}|null
     */
    private function buildForumStats(array $activeClasses): ?array
    {
        if (!in_array(ForumModule::class, $activeClasses, true)) {
            return null;
        }

        $totalTopics = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(ForumTopic::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPosts = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ForumPost::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'topics' => $totalTopics,
            'posts' => $totalPosts,
            'members' => $this->forumPostRepository->countDistinctAuthors(),
            'sections' => count($this->forumSectionRepository->findAllByLocale(self::DEFAULT_LOCALE)),
            'openReports' => $this->forumPostReportRepository->countOpen(),
        ];
    }

    /**
     * @param list<string> $activeClasses
     * @return array{total: int, diskUsageBytes: int, diskUsageLabel: string, byMimeType: list<array{mime: string, count: int}>}|null
     */
    private function buildMediaStats(array $activeClasses): ?array
    {
        if (!in_array(MediaModule::class, $activeClasses, true)) {
            return null;
        }

        $bytes = $this->assetRepository->sumFileSize();

        return [
            'total' => $this->assetRepository->countAll(),
            'diskUsageBytes' => $bytes,
            'diskUsageLabel' => $this->formatBytes($bytes),
            'byMimeType' => array_map(
                static fn (array $row): array => ['mime' => (string) $row['mimeType'], 'count' => $row['count']],
                $this->assetRepository->countGroupedByMimeType(6),
            ),
        ];
    }

    /**
     * Portal/forum/blog tüm içerik türlerini tek envanterde toplar.
     *
     * @return list<array{label: string, count: int, key: string}>
     */
    private function buildContentMix(?array $blogStats, ?array $forumStats, ?array $mediaStats, array $activeClasses): array
    {
        $items = [];

        if ($blogStats !== null) {
            $items[] = [
                'key' => 'blog_posts',
                'label' => 'studio.dashboard.kind.blog_posts',
                'count' => $blogStats['total'],
            ];
        }

        if ($forumStats !== null) {
            $items[] = [
                'key' => 'forum_topics',
                'label' => 'studio.dashboard.kind.forum_topics',
                'count' => $forumStats['topics'],
            ];
            $items[] = [
                'key' => 'forum_posts',
                'label' => 'studio.dashboard.kind.forum_posts',
                'count' => $forumStats['posts'],
            ];
        }

        if ($mediaStats !== null) {
            $items[] = [
                'key' => 'media',
                'label' => 'studio.dashboard.kind.media',
                'count' => $mediaStats['total'],
            ];
        }

        if (in_array(BlogModule::class, $activeClasses, true)) {
            $items[] = [
                'key' => 'categories',
                'label' => 'studio.dashboard.kind.categories',
                'count' => $this->categoryRepository->countAll(),
            ];
            $items[] = [
                'key' => 'tags',
                'label' => 'studio.dashboard.kind.tags',
                'count' => $this->tagRepository->countAll(),
            ];
        }

        return array_values(array_filter($items, static fn (array $row): bool => $row['count'] > 0 || in_array($row['key'], ['blog_posts', 'forum_topics', 'forum_posts'], true)));
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
            ->setParameter('type', 'post')
            ->groupBy('n.status')
            ->orderBy('count', 'DESC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): array => ['status' => (string) $row['status'], 'count' => (int) $row['count']],
            $rows,
        );
    }

    /**
     * @param list<array{status: string, count: int}> $rows
     */
    private function sumStatusCount(array $rows, string $status): int
    {
        foreach ($rows as $row) {
            if ($row['status'] === $status) {
                return $row['count'];
            }
        }

        return 0;
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function buildModuleOverviewChart(?array $blogStats, ?array $forumStats, ?array $mediaStats, array $activeClasses): array
    {
        return array_map(
            static fn (array $row): array => ['label' => $row['label'], 'count' => $row['count']],
            $this->buildContentMix($blogStats, $forumStats, $mediaStats, $activeClasses),
        );
    }

    /**
     * Son 6 ay: blog yazıları, forum konuları ve kullanıcı mesajları (yorumlar).
     *
     * @param list<string> $activeClasses
     * @return array{labels: list<string>, series: list<array{label: string, values: list<int>}>}
     */
    private function buildContentTrend(array $activeClasses): array
    {
        $months = [];
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 5; $i >= 0; --$i) {
            $months[] = $now->modify("-{$i} months");
        }

        $monthKeys = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m'), $months);
        $labels = array_map(static fn (\DateTimeImmutable $d): string => $d->format('M Y'), $months);
        $since = $months[0];

        $series = [];

        if (in_array(BlogModule::class, $activeClasses, true)) {
            $series[] = [
                'label' => 'studio.dashboard.kind.blog_posts',
                'values' => $this->countNodesByMonth($since, $monthKeys),
            ];
        }

        if (in_array(ForumModule::class, $activeClasses, true)) {
            $series[] = [
                'label' => 'studio.dashboard.kind.forum_topics',
                'values' => $this->countEntitiesByMonth(ForumTopic::class, $since, $monthKeys),
            ];
            $series[] = [
                'label' => 'studio.dashboard.kind.forum_posts',
                'values' => $this->countEntitiesByMonth(ForumPost::class, $since, $monthKeys),
            ];
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * @param list<string> $monthKeys
     * @return list<int>
     */
    private function countNodesByMonth(\DateTimeImmutable $since, array $monthKeys): array
    {
        $counts = array_fill_keys($monthKeys, 0);

        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.createdAt')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.createdAt >= :since')
            ->setParameter('type', 'post')
            ->setParameter('since', $since)
            ->orderBy('n.createdAt', 'ASC');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        foreach ($qb->getQuery()->getResult() as $row) {
            $createdAt = is_array($row) ? ($row['createdAt'] ?? null) : null;
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

    /**
     * @param class-string $entityClass
     * @param list<string> $monthKeys
     * @return list<int>
     */
    private function countEntitiesByMonth(string $entityClass, \DateTimeImmutable $since, array $monthKeys): array
    {
        $counts = array_fill_keys($monthKeys, 0);

        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.createdAt')
            ->from($entityClass, 'e')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $createdAt = is_array($row) ? ($row['createdAt'] ?? null) : null;
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

    /**
     * @return list<array{label: string, value: int}>
     */
    private function buildModuleRadar(?array $blogStats, ?array $forumStats, ?array $mediaStats, array $activeClasses): array
    {
        $items = [];
        if ($blogStats !== null) {
            $items[] = ['label' => 'studio.dashboard.kind.blog_posts', 'value' => $blogStats['total']];
        }
        if ($forumStats !== null) {
            $items[] = ['label' => 'studio.dashboard.kind.forum_topics', 'value' => $forumStats['topics']];
            $items[] = ['label' => 'studio.dashboard.kind.forum_posts', 'value' => $forumStats['posts']];
        }
        if ($mediaStats !== null) {
            $items[] = ['label' => 'studio.dashboard.kind.media', 'value' => $mediaStats['total']];
        }
        if (in_array(BlogModule::class, $activeClasses, true)) {
            $items[] = ['label' => 'studio.dashboard.kind.categories', 'value' => $this->categoryRepository->countAll()];
            $items[] = ['label' => 'studio.dashboard.kind.tags', 'value' => $this->tagRepository->countAll()];
        }

        return $items;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function buildBlogRadar(?array $blogStats): array
    {
        if ($blogStats === null) {
            return [];
        }

        return [
            ['label' => 'studio.dashboard.published', 'value' => $blogStats['published']],
            ['label' => 'studio.dashboard.draft', 'value' => $blogStats['draft']],
            ['label' => 'studio.dashboard.blog.scheduled', 'value' => $blogStats['scheduled']],
            ['label' => 'studio.dashboard.blog.categories', 'value' => $blogStats['categories']],
            ['label' => 'studio.dashboard.blog.tags', 'value' => $blogStats['tags']],
        ];
    }

    /**
     * @param list<string> $activeClasses
     * @return array{labels: list<string>, postCounts: list<int>, topicCounts: list<int>}|null
     */
    private function buildForumSectionCharts(array $activeClasses): ?array
    {
        if (!in_array(ForumModule::class, $activeClasses, true)) {
            return null;
        }

        $sections = $this->forumSectionRepository->findAllByLocale(self::DEFAULT_LOCALE);
        $chartSections = array_values(array_filter(
            $sections,
            static fn (ForumSection $s): bool => $s->allowsTopics() || $s->getTopicCount() > 0 || $s->getPostCount() > 0,
        ));
        usort($chartSections, static fn (ForumSection $a, ForumSection $b): int => $b->getPostCount() <=> $a->getPostCount());
        $chartSections = \array_slice($chartSections, 0, 8);

        if ($chartSections === []) {
            return null;
        }

        return [
            'labels' => array_map(static fn (ForumSection $s): string => $s->getTitle(), $chartSections),
            'postCounts' => array_map(static fn (ForumSection $s): int => $s->getPostCount(), $chartSections),
            'topicCounts' => array_map(static fn (ForumSection $s): int => $s->getTopicCount(), $chartSections),
        ];
    }

    /**
     * @param list<string> $activeClasses
     * @param list<array{label: string, icon: string, routeName: string, routePrefix: string, group: ?string, children: list<array{label: string, icon: string, routeName: string, routePrefix: string}>}> $menuTree
     * @return list<array{key: string, name: string, icon: string, links: list<array{label: string, icon: string, routeName: string}>}>
     */
    private function buildModuleCards(array $activeClasses, array $menuTree): array
    {
        $discovered = [];
        foreach ($this->moduleRegistry->discoverAllModules() as $module) {
            if ($module['class'] !== null) {
                $discovered[$module['class']] = $module['name'];
            }
        }

        $linksByKey = $this->groupMenuLinks($menuTree);
        $cards = [];

        foreach (self::STUDIO_MODULES as $meta) {
            if (!in_array($meta['class'], $activeClasses, true)) {
                continue;
            }

            $links = $linksByKey[$meta['key']] ?? [];
            if ($links === []) {
                $links = $meta['fallbackLinks'];
            }

            $cards[] = [
                'key' => $meta['key'],
                'name' => $discovered[$meta['class']] ?? ucfirst($meta['key']),
                'icon' => $meta['icon'],
                'links' => $links,
            ];
        }

        return $cards;
    }

    /**
     * @param list<array{label: string, icon: string, routeName: string, routePrefix: string, group: ?string, children: list<array{label: string, icon: string, routeName: string, routePrefix: string}>}> $menuTree
     * @return array<string, list<array{label: string, icon: string, routeName: string}>>
     */
    private function groupMenuLinks(array $menuTree): array
    {
        $grouped = [];

        foreach ($menuTree as $item) {
            if ($item['routeName'] === 'admin_dashboard') {
                continue;
            }

            $key = $this->moduleKeyFromRoutePrefix($item['routePrefix']);
            if ($key === null) {
                continue;
            }

            $grouped[$key][] = [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'routeName' => $item['routeName'],
            ];

            foreach ($item['children'] as $child) {
                $grouped[$key][] = [
                    'label' => $child['label'],
                    'icon' => $child['icon'],
                    'routeName' => $child['routeName'],
                ];
            }
        }

        return $grouped;
    }

    private function moduleKeyFromRoutePrefix(string $routePrefix): ?string
    {
        if (str_starts_with($routePrefix, 'admin_posts') || str_starts_with($routePrefix, 'admin_categories') || str_starts_with($routePrefix, 'admin_tags')) {
            return 'blog';
        }
        if (str_starts_with($routePrefix, 'admin_forum')) {
            return 'forum';
        }
        if (str_starts_with($routePrefix, 'admin_media')) {
            return 'media';
        }

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
