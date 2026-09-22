<?php

declare(strict_types=1);

namespace Modules\Forum\Rebuild;

use App\Core\Rebuild\RebuilderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Service\ForumStatsService;

/**
 * Recount each topic's visible / held / deleted post buckets.
 * COUNT(*) is legal here; the write path uses ForumCounterService.
 */
final class ForumTopicRebuilder implements RebuilderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumStatsService $statsService,
    ) {
    }

    public function getId(): string
    {
        return 'forum.topics';
    }

    public function getName(): string
    {
        return 'forum.rebuild.topics';
    }

    public function getDescription(): string
    {
        return 'forum.rebuild.topics_desc';
    }

    public function getBatchSize(): int
    {
        return 200;
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getTotal(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM cp_forum_topics');
    }

    public function isStudioVisible(): bool
    {
        return true;
    }

    public function rebuild(int $offset, int $limit): int
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(sprintf(
            'SELECT id FROM cp_forum_topics ORDER BY id ASC LIMIT %d OFFSET %d',
            max(0, $limit),
            max(0, $offset),
        ));

        $visited = $this->statsService->rebuildTopicsByIds(array_map('intval', $ids));
        $this->entityManager->clear();

        return $visited;
    }
}
