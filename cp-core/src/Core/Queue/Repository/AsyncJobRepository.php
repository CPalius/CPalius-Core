<?php

declare(strict_types=1);

namespace App\Core\Queue\Repository;

use App\Core\Queue\AsyncJobLease;
use App\Core\Queue\Entity\AsyncJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AsyncJob>
 */
class AsyncJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AsyncJob::class);
    }

    /**
     * Due jobs this caller now owns. Each one is leased through AsyncJobLease, so a concurrent worker
     * cannot receive the same job.
     *
     * @return list<AsyncJob>
     */
    public function claimDue(int $limit, \DateTimeImmutable $now): array
    {
        /** @var list<AsyncJob> $candidates */
        $candidates = $this->createQueryBuilder('j')
            ->andWhere('j.processedAt IS NULL')
            ->andWhere('j.availableAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('j.availableAt', 'ASC')
            ->addOrderBy('j.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();
        $claimed = [];

        foreach ($candidates as $job) {
            if (!AsyncJobLease::tryClaim($connection, (int) $job->getId(), $job->getAvailableAt(), $now)) {
                // Another worker won this one.
                $entityManager->detach($job);
                continue;
            }

            // No refresh: the entity keeps the old available_at, so the flush leaves the lease in place unless the
            // handler changes it (markRetry sets the next attempt), and no extra query runs per job.
            $claimed[] = $job;
        }

        return $claimed;
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.processedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFailed(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.failedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{id: int, type: string, attempts: int, availableAt: string, lastError: ?string, failed: bool}>
     */
    public function listRecent(int $limit = 50, bool $failedOnly = false): array
    {
        $qb = $this->createQueryBuilder('j')
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)));

        if ($failedOnly) {
            $qb->andWhere('j.failedAt IS NOT NULL');
        } else {
            $qb->andWhere('j.processedAt IS NULL OR j.failedAt IS NOT NULL');
        }

        /** @var list<AsyncJob> $jobs */
        $jobs = $qb->getQuery()->getResult();
        $out = [];
        foreach ($jobs as $job) {
            $out[] = [
                'id' => (int) $job->getId(),
                'type' => $job->getType(),
                'attempts' => $job->getAttempts(),
                'availableAt' => $job->getAvailableAt()->format('Y-m-d H:i:s'),
                'lastError' => $job->getLastError(),
                'failed' => $job->getFailedAt() !== null,
            ];
        }

        return $out;
    }
}
