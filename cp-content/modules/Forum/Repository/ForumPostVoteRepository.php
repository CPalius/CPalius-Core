<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostVote;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDiscussionState;

/**
 * Replaces ForumPostLikeRepository and ForumPostDislikeRepository, which were
 * identical line for line apart from the entity name and the query alias.
 *
 * Every method that used to exist twice now takes the verdict as an argument.
 *
 * @extends ServiceEntityRepository<ForumPostVote>
 */
final class ForumPostVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostVote::class);
    }

    /**
     * The member's vote on this post, whichever way it went — there can only be
     * one, so the caller does not have to ask twice.
     */
    public function findOneByPostAndUser(ForumPost $post, User $user): ?ForumPostVote
    {
        return $this->findOneBy(['post' => $post, 'user' => $user]);
    }

    public function countByPost(ForumPost $post, int $vote): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.post = :post')
            ->andWhere('v.vote = :vote')
            ->setParameter('post', $post)
            ->setParameter('vote', $vote)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vote counts keyed by post id — one query instead of N+1 (Manifesto Law 6.1).
     *
     * @param int[] $postIds
     *
     * @return array<int, int>
     */
    public function countByPostIds(array $postIds, int $vote): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.post) AS postId, COUNT(v.id) AS cnt')
            ->andWhere('v.post IN (:postIds)')
            ->andWhere('v.vote = :vote')
            ->setParameter('postIds', $postIds)
            ->setParameter('vote', $vote)
            ->groupBy('v.post')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['postId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * Both tallies for a thread in one query rather than one call per verdict —
     * the thread view needs likes and dislikes together every time.
     *
     * @param int[] $postIds
     *
     * @return array<int, array{likes: int, dislikes: int}>
     */
    public function countBothByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.post) AS postId, v.vote AS vote, COUNT(v.id) AS cnt')
            ->andWhere('v.post IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->groupBy('v.post')
            ->addGroupBy('v.vote')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $postId = (int) $row['postId'];
            $map[$postId] ??= ['likes' => 0, 'dislikes' => 0];
            $key = (int) $row['vote'] === ForumPostVote::LIKE ? 'likes' : 'dislikes';
            $map[$postId][$key] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * Post ids this member voted the given way, as { postId: true } — one query
     * for the thread's vote state.
     *
     * @param int[] $postIds
     *
     * @return array<int, true>
     */
    public function findVotedPostIdsForUser(array $postIds, User $user, int $vote): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.post) AS postId')
            ->andWhere('v.post IN (:postIds)')
            ->andWhere('v.user = :user')
            ->andWhere('v.vote = :vote')
            ->setParameter('postIds', $postIds)
            ->setParameter('user', $user)
            ->setParameter('vote', $vote)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['postId']] = true;
        }

        return $map;
    }

    /**
     * Public posts this member voted on the given way — profile tab.
     */
    public function createPublicByUserQueryBuilder(User $user, int $vote): QueryBuilder
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.post', 'p')->addSelect('p')
            ->innerJoin('p.topic', 't')->addSelect('t')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('v.user = :user')
            ->andWhere('v.vote = :vote')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('user', $user)
            ->setParameter('vote', $vote)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('v.createdAt', 'DESC');
    }

    /**
     * Last vote timestamp per user in this thread — one grouped query (Law 6.1).
     *
     * @return array<int, int> userId => unix timestamp
     */
    public function findUserLastActivityByTopic(ForumTopic $topic, ?int $vote = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.user) AS userId, MAX(v.createdAt) AS lastAt')
            ->innerJoin('v.post', 'p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->groupBy('v.user');

        if ($vote !== null) {
            $qb->andWhere('v.vote = :vote')->setParameter('vote', $vote);
        }

        $rows = $qb->getQuery()->getArrayResult();

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
