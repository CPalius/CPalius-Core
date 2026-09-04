<?php

declare(strict_types=1);

namespace App\Core\Audit\Repository;

use App\Core\Audit\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Full change history of a single row, newest first.
     *
     * @return list<AuditLog>
     */
    public function findForResource(string $resourceName, string $resourceId, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.resourceName = :name')
            ->andWhere('a.resourceId = :id')
            ->setParameter('name', $resourceName)
            ->setParameter('id', $resourceId)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recent activity across every audited resource, newest first.
     *
     * @return list<AuditLog>
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every entry produced by one user, newest first.
     *
     * @return list<AuditLog>
     */
    public function findByUser(int $userId, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
