<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostLike;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;

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
     * Like counts keyed by post id — one query instead of N+1 (Manifesto Law 6.1).
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
     * Liked post ids for the given user as { postId: true } — one query for the thread like state.
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

    /**
     * Public posts this member liked — profile tab.
     */
    public function createPublicByUserQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.post', 'p')->addSelect('p')
            ->innerJoin('p.topic', 't')->addSelect('t')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('l.user = :user')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('user', $user)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('l.createdAt', 'DESC');
    }

    /**
     * Last like timestamp per user in this thread — one grouped query (Law 6.1).
     *
     * @return array<int, int> userId => unix timestamp
     */
    public function findUserLastActivityByTopic(ForumTopic $topic): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.user) AS userId, MAX(l.createdAt) AS lastAt')
            ->innerJoin('l.post', 'p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->groupBy('l.user')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row['userId'];
            if ($id <= 0) {
                continue;
            }
            $at = $row['lastAt'];
            $map[$id] = $at instanceof \DateTimeInterface ? $at->getTimestamp() : (strtotime((string) $at) ?: 0);
        }

        return $map;
    }
}
