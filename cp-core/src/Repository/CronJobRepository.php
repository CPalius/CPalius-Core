<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronJob>
 */
class CronJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronJob::class);
    }

    /**
     * cp:cron:run dispatcher'ının TEK sorguda okuduğu aktif iş listesi —
     * "zamanı geldi mi?" hesaplaması burada DEĞİL, CronExpressionEvaluator
     * içinde yapılır (bu repository sadece "aktif olanlar" filtresini
     * DB seviyesinde uygular, cron ifadesi eşleştirmesi PHP tarafında).
     *
     * @return list<CronJob>
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.active = true')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CronJob>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
