<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\ForumDiscussionState;

/**
 * @extends ServiceEntityRepository<ForumPost>
 */
final class ForumPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPost::class);
    }

    public function createTopicPostsQueryBuilder(ForumTopic $topic, bool $includeHeld = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'ASC');

        if (!$includeHeld) {
            $qb->andWhere('p.discussionState = :visible')
                ->setParameter('visible', ForumDiscussionState::Visible);
        }

        return $qb;
    }

    /** @return list<ForumPost> */
    public function findHeld(int $limit = 80): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.topic', 't')->addSelect('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->andWhere('p.discussionState = :held OR t.discussionState = :held')
            ->setParameter('held', ForumDiscussionState::Moderated)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.author = :author')
            ->andWhere('p.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTopic(ForumTopic $topic): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBySection(ForumSection $section): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.topic', 't')
            ->andWhere('t.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ForumPost>
     */
    public function findLatestPublic(int $limit = 10, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.topic', 't')->addSelect('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLastByTopic(ForumTopic $topic): ?ForumPost
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findFirstByTopic(ForumTopic $topic): ?ForumPost
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return ForumPost[] */
    public function findLatest(int $limit = 10, ?string $contentLocale = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.topic', 't')->addSelect('t')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($contentLocale !== null && $contentLocale !== '') {
            $qb->andWhere('t.locale = :contentLocale')
                ->setParameter('contentLocale', $contentLocale);
        }

        return $qb->getQuery()->getResult();
    }

    public function countVisiblePublic(?string $contentLocale = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.topic', 't')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible);

        if ($contentLocale !== null && $contentLocale !== '') {
            $qb->andWhere('t.locale = :contentLocale')
                ->setParameter('contentLocale', $contentLocale);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return ForumPost[] */
    public function findByTopic(ForumTopic $topic): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getResult();
    }

    /**
     * Distinct members who posted in the topic (participants).
     *
     * @return list<User>
     */
    public function findDistinctAuthorsByTopic(ForumTopic $topic): array
    {
        // Root must be the selected entity (User); selecting only a joined
        // alias from ForumPost triggers Doctrine Semantical Error.
        /** @var list<User> $authors */
        $authors = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->innerJoin(ForumPost::class, 'p', Join::WITH, 'p.author = u AND p.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getResult();

        return $authors;
    }

    /**
     * Public reply count for profile/postbit; private-topic posts are excluded.
     */
    public function countPublicByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.author = :author')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Paginated { userId: postCount } map for the member directory, grouped in one query.
     *
     * @return array<int, int>
     */
    public function findMemberPostCounts(int $limit, int $offset): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS userId, COUNT(p.id) AS cnt')
            ->andWhere('p.author IS NOT NULL')
            ->groupBy('p.author')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    public function countPublicPostsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS userId, COUNT(p.id) AS cnt')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.author IN (:userIds)')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('userIds', $userIds)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->groupBy('p.author')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }

    public function countCreatedSince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDistinctAuthors(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.author)')
            ->andWhere('p.author IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function createPublicByAuthorQueryBuilder(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.author = :author')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.discussionState = :visible')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->setParameter('visible', ForumDiscussionState::Visible)
            ->orderBy('p.createdAt', 'DESC');
    }
}
