<?php

declare(strict_types=1);

namespace Modules\Pages\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use App\Core\Security\QueryScopeApplier;
use App\Repository\NodeRepository;
use Modules\Pages\Controller\Admin\PageAdminController;

final class PagesStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly QueryScopeApplier $queryScopeApplier,
    ) {
    }

    public function getKey(): string
    {
        return 'pages';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.pages';
    }

    public function getIcon(): string
    {
        return 'heroicons:document-duplicate';
    }

    public function getPriority(): int
    {
        return 18;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $byStatus = $this->scopedCountsByStatus();
        $published = $this->sumStatus($byStatus, 'published');
        $draft = $this->sumStatus($byStatus, 'draft') + $this->sumStatus($byStatus, 'scheduled');

        return new StudioDashboardContribution(
            publishedCount: $published,
            draftCount: $draft,
            mixItems: [
                ['key' => 'pages', 'labelKey' => 'studio.dashboard.kind.pages', 'count' => $published + $draft],
            ],
        );
    }

    /**
     * @return list<array{status: string, count: int}>
     */
    private function scopedCountsByStatus(): array
    {
        $qb = $this->nodeRepository->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS count')
            ->andWhere('n.type = :type')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', PageAdminController::NODE_TYPE)
            ->groupBy('n.status');

        $this->queryScopeApplier->apply($qb, 'n', 'node.page.view', 'author');

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
