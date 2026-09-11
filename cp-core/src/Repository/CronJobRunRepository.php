<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronJob;
use App\Entity\CronJobRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronJobRun>
 */
class CronJobRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronJobRun::class);
    }

    /**
     * Latest cron executions across every job, newest first.
     *
     * @return list<CronJobRun>
     */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.cronJob', 'j')->addSelect('j')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Last N runs of one job, newest first.
     *
     * @return list<CronJobRun>
     */
    public function findRecentByJob(CronJob $cronJob, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.cronJob = :cronJob')
            ->setParameter('cronJob', $cronJob)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts consecutive failures from newest runs until success or unfinished run.
     */
    public function countConsecutiveFailures(CronJob $cronJob, int $lookback = 5): int
    {
        $recentRuns = $this->findRecentByJob($cronJob, $lookback);

        $consecutiveFailures = 0;
        foreach ($recentRuns as $run) {
            if ($run->isSuccess() !== false) {
                break;
            }

            ++$consecutiveFailures;
        }

        return $consecutiveFailures;
    }
}
