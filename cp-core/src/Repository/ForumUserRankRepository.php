<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumUserRank;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
     * Mesaj sayısı eşiğini geçen, otomatik atanabilir rütbeler arasından
     * en yükseğini döndürür (minPosts DESC ilk eşleşen).
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
