<?php

declare(strict_types=1);

namespace App\Core\Admin;

use App\Core\Module\ModuleContributionCatalog;
use App\Core\Security\QueryScopeApplier;
use App\Entity\Node;
use App\Repository\AssetRepository;
use App\Repository\NodeRepository;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Studio command desk — core nodes/assets plus tagged module providers
 * and Resources/config/contributions.yaml (no hardcoded module names).
 */
final class StudioDashboardService
{
    /**
     * @param iterable<StudioDashboardStatsProviderInterface> $statsProviders
     */
    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly AssetRepository $assetRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ModuleContributionCatalog $contributions,
        #[TaggedIterator('cpalius.studio.dashboard_stats_provider')]
        private readonly iterable $statsProviders,
    ) {
    }

    /**
     * @return array{
     *     kpis: array{
     *         published: int,
     *         drafts: int,
     *         mediaBytes: int,
     *         mediaLabel: string,
     *         extraKpis: list<array{key: string, labelKey: string, value: int|string}>
     *     },
     *     mix: list<array{key: string, labelKey: string, count: int}>,
     *     recentActivity: list<array{
     *         title: string,
     *         typeKey: string,
     *         author: string,
     *         updatedAt: \DateTimeImmutable,
     *         status: string,
     *         editUrl: ?string,
     *         viewUrl: ?string
     *     }>,
     *     quickCreate: list<array{labelKey: string, routeName: string, icon: string}>,
     *     quickLinks: list<array{labelKey: string, routeName: string, icon: string}>
     * }
     */
    public function build(): array
    {
        $published = 0;
        $drafts = 0;
        foreach ($this->scopedStatusCounts() as $status => $count) {
            if ($status === Node::STATUS_PUBLISHED) {
                $published += $count;
            } elseif ($status === Node::STATUS_DRAFT || $status === Node::STATUS_SCHEDULED) {
                $drafts += $count;
            }
        }

        $mediaBytes = $this->assetRepository->sumFileSize();
        $extraKpis = [];
        $mix = [];

        foreach ($this->sortedProviders() as $provider) {
            try {
                $contribution = $provider->buildContribution();
            } catch (\Throwable) {
                continue;
            }

            if ($contribution->mediaBytes > 0) {
                $mediaBytes = $contribution->mediaBytes;
            }

            foreach ($contribution->extraKpis as $kpi) {
                if (\is_array($kpi) && isset($kpi['key'], $kpi['labelKey'], $kpi['value'])) {
                    $extraKpis[] = $kpi;
                }
            }

            if ($contribution->forumPostsLast24h > 0) {
                $extraKpis[] = [
                    'key' => 'forum_24h',
                    'labelKey' => 'studio.dashboard.kpi.forum_24h',
                    'value' => $contribution->forumPostsLast24h,
                ];
            }

            foreach ($contribution->mixItems as $item) {
                $mix[] = $item;
            }
        }

        return [
            'kpis' => [
                'published' => $published,
                'drafts' => $drafts,
                'mediaBytes' => $mediaBytes,
                'mediaLabel' => $this->formatBytes($mediaBytes),
                'extraKpis' => $extraKpis,
            ],
            'mix' => $mix,
            'recentActivity' => $this->buildRecentActivity(),
            'quickCreate' => $this->existingLinks($this->contributions->studioQuickCreate()),
            'quickLinks' => $this->existingLinks($this->contributions->studioQuickLinks()),
        ];
    }

    /**
     * @return list<StudioDashboardStatsProviderInterface>
     */
    private function sortedProviders(): array
    {
        $providers = [];
        foreach ($this->statsProviders as $provider) {
            if ($provider instanceof StudioDashboardStatsProviderInterface) {
                $providers[] = $provider;
            }
        }

        usort(
            $providers,
            static fn (StudioDashboardStatsProviderInterface $a, StudioDashboardStatsProviderInterface $b): int => $a->getPriority() <=> $b->getPriority(),
        );

        return $providers;
    }

    /**
     * @return array<string, int>
     */
    private function scopedStatusCounts(): array
    {
        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS count')
            ->andWhere('n.deletedAt IS NULL')
            ->groupBy('n.status');

        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');

        $counts = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $counts[(string) $row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @return list<array{
     *     title: string,
     *     typeKey: string,
     *     author: string,
     *     updatedAt: \DateTimeImmutable,
     *     status: string,
     *     editUrl: ?string,
     *     viewUrl: ?string
     * }>
     */
    private function buildRecentActivity(): array
    {
        $qb = $this->nodeRepository->createRecentlyUpdatedQueryBuilder()
            ->setMaxResults(10);
        $this->queryScopeApplier->apply($qb, 'n', 'node.post.view', 'author');
        /** @var list<Node> $nodes */
        $nodes = $qb->getQuery()->getResult();

        $hidden = $this->contributions->hiddenNodeTypes();
        $typeLabels = $this->contributions->studioTypeLabels();

        $rows = [];
        foreach ($nodes as $node) {
            $type = $node->getType();
            if (\in_array($type, $hidden, true)) {
                continue;
            }
            $rows[] = [
                'title' => $node->getTitle(),
                'typeKey' => $typeLabels[$type] ?? 'studio.dashboard.type.other',
                'author' => $node->getAuthor()?->getFullName() ?: '—',
                'updatedAt' => $node->getUpdatedAt(),
                'status' => $node->getStatus(),
                'editUrl' => $this->nodeEditUrl($node),
                'viewUrl' => $node->getStatus() === Node::STATUS_PUBLISHED ? $this->nodeViewUrl($node) : null,
            ];
        }

        return $rows;
    }

    private function nodeEditUrl(Node $node): ?string
    {
        $map = $this->contributions->studioEditRoutes()[$node->getType()] ?? null;
        if ($map === null || $node->getId() === null) {
            return null;
        }

        return $this->safeUrl($map[0], [$map[1] => $node->getId()]);
    }

    private function nodeViewUrl(Node $node): ?string
    {
        $map = $this->contributions->studioViewRoutes()[$node->getType()] ?? null;
        if ($map === null) {
            return null;
        }

        return $this->safeUrl($map[0], [
            $map[1] => $node->getSlug(),
            '_locale' => $node->getLocale(),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function safeUrl(string $routeName, array $params): ?string
    {
        try {
            return $this->urlGenerator->generate($routeName, $params);
        } catch (RouteNotFoundException|\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{labelKey: string, routeName: string, icon: string}> $links
     *
     * @return list<array{labelKey: string, routeName: string, icon: string}>
     */
    private function existingLinks(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            try {
                $this->urlGenerator->generate($link['routeName']);
                $out[] = $link;
            } catch (RouteNotFoundException|\Throwable) {
                continue;
            }
        }

        return $out;
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
