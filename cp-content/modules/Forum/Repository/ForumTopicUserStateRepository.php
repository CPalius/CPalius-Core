<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicUserState;

/**
 * Replaces ForumTopicViewRepository and ForumTopicWatchRepository.
 *
 * @extends ServiceEntityRepository<ForumTopicUserState>
 */
final class ForumTopicUserStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopicUserState::class);
    }

    public function findOneByTopicAndUser(ForumTopic $topic, User $user): ?ForumTopicUserState
    {
        return $this->findOneBy(['topic' => $topic, 'user' => $user]);
    }

    /**
     * The row for this pair, created (unflushed) when it does not exist yet —
     * callers always want one, and there can only ever be one.
     */
    public function findOrCreate(ForumTopic $topic, User $user): ForumTopicUserState
    {
        $state = $this->findOneByTopicAndUser($topic, $user);
        if ($state === null) {
            $state = new ForumTopicUserState($topic, $user);
            $this->getEntityManager()->persist($state);
        }

        return $state;
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function lastSeenByTopicIds(User $user, array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('IDENTITY(s.topic) IN (:ids)')
            ->andWhere('s.lastSeenAt IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('ids', $topicIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $id = $row->getTopic()->getId();
            $seen = $row->getLastSeenAt();
            if ($id !== null && $seen !== null) {
                $map[$id] = $seen;
            }
        }

        return $map;
    }

    /**
     * Members who have opened this thread.
     */
    public function countReadersByTopic(ForumTopic $topic): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.topic = :topic')
            ->andWhere('s.lastSeenAt IS NOT NULL')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recent unique readers with the User already joined (Law 6.1).
     *
     * @return list<ForumTopicUserState>
     */
    public function findRecentReadersByTopic(ForumTopic $topic, int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')->addSelect('u')
            ->andWhere('s.topic = :topic')
            ->andWhere('s.lastSeenAt IS NOT NULL')
            ->setParameter('topic', $topic)
            ->orderBy('s.lastSeenAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Members watching this thread, User already joined.
     *
     * @return list<ForumTopicUserState>
     */
    public function findWatchersByTopic(ForumTopic $topic): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')->addSelect('u')
            ->andWhere('s.topic = :topic')
            ->andWhere('s.watchingSince IS NOT NULL')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getResult();
    }
}
