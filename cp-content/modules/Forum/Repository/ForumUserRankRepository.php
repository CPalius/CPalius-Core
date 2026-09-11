<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumUserRank;

/**
 * @extends ServiceEntityRepository<ForumUserRank>
 */
final class ForumUserRankRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumUserRank::class);
    }

    /** @return ForumUserRank[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.sortOrder', 'ASC')
            ->addOrderBy('r.minPosts', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Highest auto-awardable rank whose minPosts threshold the user meets (minPosts DESC).
     */
    public function findHighestAutomaticForPostCount(int $postCount): ?ForumUserRank
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.minPosts IS NOT NULL')
            ->andWhere('r.minPosts <= :postCount')
            ->setParameter('postCount', $postCount)
            ->orderBy('r.minPosts', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
