<?php

declare(strict_types=1);

namespace Modules\Forum\Rebuild;

use App\Core\Rebuild\RebuilderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Service\ForumStatsService;

/**
 * Rebuild parent_path, then COUNT(*) roll-up of topic/post/last_post onto each section.
 */
final class ForumSectionRebuilder implements RebuilderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumStatsService $statsService,
    ) {
    }

    public function getId(): string
    {
        return 'forum.sections';
    }

    public function getName(): string
    {
        return 'forum.rebuild.sections';
    }

    public function getDescription(): string
    {
        return 'forum.rebuild.sections_desc';
    }

    public function getBatchSize(): int
    {
        return 50;
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function getTotal(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM cp_forum_sections');
    }

    public function isStudioVisible(): bool
    {
        return true;
    }

    public function rebuild(int $offset, int $limit): int
    {
        if ($offset === 0) {
            $this->rebuildParentPaths();
        }

        $ids = $this->entityManager->getConnection()->fetchFirstColumn(sprintf(
            'SELECT id FROM cp_forum_sections ORDER BY id ASC LIMIT %d OFFSET %d',
            max(0, $limit),
            max(0, $offset),
        ));

        $visited = $this->statsService->rebuildSectionsByIds(array_map('intval', $ids));
        $this->entityManager->clear();

        return $visited;
    }

    private function rebuildParentPaths(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            "UPDATE cp_forum_sections SET parent_path = CONCAT('/', id, '/') WHERE parent_id IS NULL",
        );

        $guard = 0;
        do {
            $changed = $conn->executeStatement(
                "UPDATE cp_forum_sections c
                 INNER JOIN cp_forum_sections p ON p.id = c.parent_id
                 SET c.parent_path = CONCAT(p.parent_path, c.id, '/')
                 WHERE c.parent_id IS NOT NULL
                   AND c.parent_path <> CONCAT(p.parent_path, c.id, '/')",
            );
        } while ($changed > 0 && ++$guard < 32);
    }
}
