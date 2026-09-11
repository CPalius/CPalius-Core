<?php

declare(strict_types=1);

namespace App\Core\Queue\Repository;

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
     * @return list<AsyncJob>
     */
    public function claimDue(int $limit, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.processedAt IS NULL')
            ->andWhere('j.availableAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('j.availableAt', 'ASC')
            ->addOrderBy('j.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
