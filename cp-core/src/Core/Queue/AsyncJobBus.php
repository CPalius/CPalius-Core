<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Queue\Entity\AsyncJob;
use App\Core\Queue\Repository\AsyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enqueue-only bus. Delivery happens in QueueWorker — never inside the HTTP thread.
 */
final class AsyncJobBus implements AsyncJobDispatcherInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AsyncJobRepository $repository,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $type, array $payload, ?string $tenantId = null, ?\DateTimeImmutable $availableAt = null): AsyncJob
    {
        $job = new AsyncJob($type, $payload, $tenantId, $availableAt);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    public function pendingCount(): int
    {
        return $this->repository->countPending();
    }
}
