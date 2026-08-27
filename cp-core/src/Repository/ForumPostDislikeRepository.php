<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumPost;
use App\Entity\ForumPostDislike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPostDislike>
 */
final class ForumPostDislikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostDislike::class);
    }

    public function findOneByPostAndUser(ForumPost $post, User $user): ?ForumPostDislike
    {
        return $this->findOneBy(['post' => $post, 'user' => $user]);
    }

    public function countByPost(ForumPost $post): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.post = :post')
            ->setParameter('post', $post)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $postIds
     *
     * @return array<int, int>
     */
    public function countByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.post) AS postId, COUNT(d.id) AS cnt')
            ->andWhere('d.post IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->groupBy('d.post')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['postId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * @param int[] $postIds
     *
     * @return array<int, true>
     */
    public function findDislikedPostIdsForUser(array $postIds, User $user): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.post) AS postId')
            ->andWhere('d.post IN (:postIds)')
            ->andWhere('d.user = :user')
            ->setParameter('postIds', $postIds)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['postId']] = true;
        }

        return $map;
    }
}
