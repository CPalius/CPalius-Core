<?php

declare(strict_types=1);

namespace Modules\Forum\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Localization\LocaleProvider;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPostReportRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ForumStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $forumPostRepository,
        private readonly ForumSectionRepository $forumSectionRepository,
        private readonly ForumPostReportRepository $forumPostReportRepository,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function getKey(): string
    {
        return 'forum';
    }

    public function getLabel(): string
    {
        return 'Forum';
    }

    public function getIcon(): string
    {
        return 'heroicons:chat-bubble-left-right';
    }

    public function getPriority(): int
    {
        return 26;
    }

    public function getRoutePrefixes(): array
    {
        return ['admin_forum_'];
    }

    public function getWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'forum.moderation_gauge', 'titleKey' => 'studio.dashboard.chart.forum_moderation'],
            ['widgetId' => 'forum.section_pie', 'titleKey' => 'studio.dashboard.chart.forum_section_pie'],
            ['widgetId' => 'forum.section_bar', 'titleKey' => 'studio.dashboard.chart.forum_section_bar'],
            ['widgetId' => 'forum.activity_line', 'titleKey' => 'studio.dashboard.chart.forum_activity'],
            ['widgetId' => 'forum.links', 'titleKey' => 'studio.dashboard.widget.quick_links'],
        ];
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $topics = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(ForumTopic::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        $posts = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ForumPost::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        $openReports = $this->forumPostReportRepository->countOpen();
        $totalReports = $this->forumPostReportRepository->countTotal();
        $sectionsChart = $this->buildSectionCharts();

        $panels = [
            [
                'widgetId' => 'forum.moderation_gauge',
                'layout' => 'tall',
                'titleKey' => 'studio.dashboard.chart.forum_moderation',
                'chart' => [
                    'type' => 'gauge-reports',
                    'open' => $openReports,
                    'total' => $totalReports,
                    'center' => $openReports,
                    'centerLabelKey' => 'studio.dashboard.forum.open_reports',
                ],
            ],
        ];

        if ($sectionsChart !== null) {
            $panels[] = [
                'widgetId' => 'forum.section_pie',
                'layout' => 'tall',
                'titleKey' => 'studio.dashboard.chart.forum_section_pie',
                'chart' => [
                    'type' => 'pie',
                    'labels' => $sectionsChart['labels'],
                    'values' => $sectionsChart['postCounts'],
                ],
            ];
            $panels[] = [
                'widgetId' => 'forum.section_bar',
                'layout' => 'tall',
                'titleKey' => 'studio.dashboard.chart.forum_section_bar',
                'chart' => [
                    'type' => 'bar-v',
                    'labels' => $sectionsChart['labels'],
                    'values' => $sectionsChart['topicCounts'],
                    'datasetLabelKey' => 'studio.dashboard.forum.topics',
                ],
            ];
            $panels[] = [
                'widgetId' => 'forum.activity_line',
                'layout' => 'wide',
                'titleKey' => 'studio.dashboard.chart.forum_activity',
                'chart' => [
                    'type' => 'line-multi',
                    'labels' => $sectionsChart['labels'],
                    'series' => [
                        ['labelKey' => 'studio.dashboard.forum.topics', 'values' => $sectionsChart['topicCounts']],
                        ['labelKey' => 'studio.dashboard.forum.posts', 'values' => $sectionsChart['postCounts']],
                    ],
                ],
            ];
        }

        $panels[] = [
            'widgetId' => 'forum.links',
            'layout' => 'wide',
            'titleKey' => 'studio.dashboard.widget.quick_links',
            'links' => true,
        ];

        return new StudioDashboardContribution(
            heroMetrics: [
                ['key' => 'forum_topics', 'labelKey' => 'studio.dashboard.kind.forum_topics', 'value' => $topics],
                ['key' => 'forum_posts', 'labelKey' => 'studio.dashboard.kind.forum_posts', 'value' => $posts],
            ],
            mixItems: [
                ['key' => 'forum_topics', 'labelKey' => 'studio.dashboard.kind.forum_topics', 'count' => $topics],
                ['key' => 'forum_posts', 'labelKey' => 'studio.dashboard.kind.forum_posts', 'count' => $posts],
            ],
            trendSeries: [
                ['labelKey' => 'studio.dashboard.kind.forum_topics', 'values' => $this->countByMonth(ForumTopic::class)],
                ['labelKey' => 'studio.dashboard.kind.forum_posts', 'values' => $this->countByMonth(ForumPost::class)],
            ],
            radarItems: [
                ['labelKey' => 'studio.dashboard.kind.forum_topics', 'value' => $topics],
                ['labelKey' => 'studio.dashboard.kind.forum_posts', 'value' => $posts],
            ],
            panels: $panels,
            links: [
                ['label' => 'studio.dashboard.link.forum', 'icon' => 'heroicons:chat-bubble-left-right', 'routeName' => 'admin_forum_dashboard'],
                ['label' => 'studio.dashboard.link.forum_topics', 'icon' => 'heroicons:chat-bubble-left-right', 'routeName' => 'admin_forum_topics_index'],
                ['label' => 'studio.dashboard.link.forum_members', 'icon' => 'heroicons:users', 'routeName' => 'admin_forum_members_index'],
            ],
        );
    }

    /**
     * @return array{labels: list<string>, postCounts: list<int>, topicCounts: list<int>}|null
     */
    private function buildSectionCharts(): ?array
    {
        $sections = $this->forumSectionRepository->findAllByLocale($this->localeProvider->getDefaultCode());
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
     * @param class-string $entityClass
     * @return list<int>
     */
    private function countByMonth(string $entityClass): array
    {
        $months = [];
        $now = new \DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 5; $i >= 0; --$i) {
            $months[] = $now->modify("-{$i} months");
        }
        $monthKeys = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m'), $months);
        $counts = array_fill_keys($monthKeys, 0);
        $since = $months[0];

        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.createdAt')
            ->from($entityClass, 'e')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
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
