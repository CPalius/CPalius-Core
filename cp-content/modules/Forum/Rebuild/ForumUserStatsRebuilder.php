<?php

declare(strict_types=1);

namespace Modules\Forum\Rebuild;

use App\Core\Rebuild\RebuilderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Service\ForumStatsService;

/**
 * Recount per-member post/topic totals, then locale board stats.
 */
final class ForumUserStatsRebuilder implements RebuilderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumStatsService $statsService,
    ) {
    }

    public function getId(): string
    {
        return 'forum.user_stats';
    }

    public function getName(): string
    {
        return 'forum.rebuild.user_stats';
    }

    public function getDescription(): string
    {
        return 'forum.rebuild.user_stats_desc';
    }

    public function getBatchSize(): int
    {
        return 200;
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getTotal(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (
                SELECT author_id AS user_id FROM cp_forum_posts WHERE author_id IS NOT NULL
                UNION
                SELECT first_poster_id FROM cp_forum_topics WHERE first_poster_id IS NOT NULL
                UNION
                SELECT user_id FROM cp_forum_user_stats
             ) u',
        );
    }

    public function isStudioVisible(): bool
    {
        return true;
    }

    public function rebuild(int $offset, int $limit): int
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(sprintf(
            'SELECT user_id FROM (
                SELECT author_id AS user_id FROM cp_forum_posts WHERE author_id IS NOT NULL
                UNION
                SELECT first_poster_id FROM cp_forum_topics WHERE first_poster_id IS NOT NULL
                UNION
                SELECT user_id FROM cp_forum_user_stats
             ) u
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d',
            max(0, $limit),
            max(0, $offset),
        ));

        $visited = $this->statsService->rebuildUsersByIds(array_map('intval', $ids));

        $next = $offset + $visited;
        if ($visited < $limit || $next >= $this->getTotal()) {
            $this->statsService->recountBoardStats();
        }

        $this->entityManager->clear();

        return $visited;
    }
}
