<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumPost;
use App\Entity\ForumPostLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPostLike>
 */
final class ForumPostLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostLike::class);
    }

    public function findOneByPostAndUser(ForumPost $post, User $user): ?ForumPostLike
    {
        return $this->findOneBy(['post' => $post, 'user' => $user]);
    }

    public function countByPost(ForumPost $post): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.post = :post')
            ->setParameter('post', $post)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Verilen mesaj id'leri için { postId: likeCount } haritası — thread
     * sayfasında N+1 sorgu yerine tek sorguda toplu okuma (Manifesto Law 6.1).
     *
     * @param int[] $postIds
     *
     * @return array<int, int>
     */
    public function countByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.post) AS postId, COUNT(l.id) AS cnt')
            ->andWhere('l.post IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->groupBy('l.post')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['postId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * Verilen kullanıcının beğendiği mesaj id'lerini { postId: true } olarak
     * döndürür — thread sayfasında "beğenildi mi?" durumunu tek sorguda çözer.
     *
     * @param int[] $postIds
     *
     * @return array<int, true>
     */
    public function findLikedPostIdsForUser(array $postIds, User $user): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.post) AS postId')
            ->andWhere('l.post IN (:postIds)')
            ->andWhere('l.user = :user')
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
