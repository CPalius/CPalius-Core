<?php

declare(strict_types=1);

namespace Modules\Blog\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Security\QueryScopeApplier;
use App\Repository\NodeRepository;
use Modules\Blog\Service\BlogCommentService;

/**
 * Supplies Blog counts to the Studio command desk via tagged iterator.
 */
final class BlogStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
        private readonly BlogCommentService $commentService,
    ) {
    }

    public function getKey(): string
    {
        return 'blog';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.blog_posts';
    }

    public function getIcon(): string
    {
        return 'heroicons:document-text';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $byStatus = $this->scopedPostCountsByStatus();
        $published = $this->sumStatus($byStatus, 'published');
        $draft = $this->sumStatus($byStatus, 'draft') + $this->sumStatus($byStatus, 'scheduled');
        $pendingComments = $this->commentService->countPending();

        return new StudioDashboardContribution(
            publishedCount: $published,
            draftCount: $draft + $pendingComments,
            mixItems: [
                ['key' => 'blog_posts', 'labelKey' => 'studio.dashboard.kind.blog_posts', 'count' => $published + $draft],
                ['key' => 'blog_comments', 'labelKey' => 'studio.dashboard.kind.blog_comments', 'count' => $pendingComments],
            ],
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
            ->groupBy('n.status');

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
}
