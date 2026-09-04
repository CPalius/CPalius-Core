<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostDislike;
use Modules\Forum\Entity\ForumTopic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\ForumDiscussionState;

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

    /**
     * Public posts this member disliked — profile tab.
     */
    public function createPublicByUserQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.post', 'p')->addSelect('p')
            ->innerJoin('p.topic', 't')->addSelect('t')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('d.user = :user')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('user', $user)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('d.createdAt', 'DESC');
    }
}
