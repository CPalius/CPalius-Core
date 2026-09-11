<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumModerationLog;

/** @extends ServiceEntityRepository<ForumModerationLog> */
final class ForumModerationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumModerationLog::class);
    }

    public function createNewestQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.actor', 'a')->addSelect('a')
            ->orderBy('l.createdAt', 'DESC');
    }

    /** @return list<ForumModerationLog> */
    public function findLatest(int $limit = 40): array
    {
        return $this->createNewestQueryBuilder()
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
