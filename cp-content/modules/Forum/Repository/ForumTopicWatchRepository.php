<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicWatch;

/** @extends ServiceEntityRepository<ForumTopicWatch> */
final class ForumTopicWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopicWatch::class);
    }

    public function findOneByTopicAndUser(ForumTopic $topic, User $user): ?ForumTopicWatch
    {
        return $this->findOneBy(['topic' => $topic, 'user' => $user]);
    }

    /** @return list<ForumTopicWatch> */
    public function findByTopic(ForumTopic $topic): array
    {
        return $this->createQueryBuilder('w')
            ->innerJoin('w.user', 'u')->addSelect('u')
            ->andWhere('w.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getResult();
    }
}
