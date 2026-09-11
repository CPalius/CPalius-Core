<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumModerationLog;
use Modules\Forum\Repository\ForumModerationLogRepository;

final class ForumModerationLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumModerationLogRepository $logRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(?User $actor, string $action, string $targetType, int $targetId, array $details = []): void
    {
        $this->entityManager->persist(new ForumModerationLog($action, $targetType, $targetId, $actor, $details));
        $this->entityManager->flush();
    }

    /** @return list<ForumModerationLog> */
    public function latest(int $limit = 40): array
    {
        return $this->logRepository->findLatest($limit);
    }
}
