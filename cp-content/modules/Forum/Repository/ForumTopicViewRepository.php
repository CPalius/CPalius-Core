<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicView;

/**
 * @extends ServiceEntityRepository<ForumTopicView>
 */
final class ForumTopicViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopicView::class);
    }

    public function findOneByTopicAndUser(ForumTopic $topic, User $user): ?ForumTopicView
    {
        return $this->findOneBy(['topic' => $topic, 'user' => $user]);
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

        $rows = $this->createQueryBuilder('v')
            ->andWhere('v.user = :user')
            ->andWhere('IDENTITY(v.topic) IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $topicIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $id = $row->getTopic()->getId();
            if ($id !== null) {
                $map[$id] = $row->getLastSeenAt();
            }
        }

        return $map;
    }

    public function countByTopic(ForumTopic $topic): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recent unique readers with the User already joined (Law 6.1).
     *
     * @return list<ForumTopicView>
     */
    public function findRecentByTopic(ForumTopic $topic, int $limit): array
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.user', 'u')->addSelect('u')
            ->andWhere('v.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('v.lastSeenAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
