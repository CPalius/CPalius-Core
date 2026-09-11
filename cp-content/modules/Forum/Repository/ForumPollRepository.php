<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPoll;
use Modules\Forum\Entity\ForumTopic;

/** @extends ServiceEntityRepository<ForumPoll> */
final class ForumPollRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPoll::class);
    }

    public function findOneByTopic(ForumTopic $topic): ?ForumPoll
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.options', 'o')->addSelect('o')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
