<?php

declare(strict_types=1);

namespace Modules\Forum\Studio;

use App\Core\Admin\StudioDashboardContribution;
use App\Core\Admin\StudioDashboardStatsProviderInterface;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Repository\ForumPostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supplies Forum mix and 24h activity to the Studio command desk.
 */
final class ForumStudioDashboardStatsProvider implements StudioDashboardStatsProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $forumPostRepository,
    ) {
    }

    public function getKey(): string
    {
        return 'forum';
    }

    public function getLabel(): string
    {
        return 'studio.dashboard.kind.forum';
    }

    public function getIcon(): string
    {
        return 'heroicons:chat-bubble-left-right';
    }

    public function getPriority(): int
    {
        return 26;
    }

    public function buildContribution(): StudioDashboardContribution
    {
        $posts = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ForumPost::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        $since = new \DateTimeImmutable('-24 hours');

        return new StudioDashboardContribution(
            extraKpis: [
                [
                    'key' => 'forum_24h',
                    'labelKey' => 'studio.dashboard.kpi.forum_24h',
                    'value' => $this->forumPostRepository->countCreatedSince($since),
                ],
            ],
            mixItems: [
                ['key' => 'forum_posts', 'labelKey' => 'studio.dashboard.kind.forum', 'count' => $posts],
            ],
        );
    }
}
