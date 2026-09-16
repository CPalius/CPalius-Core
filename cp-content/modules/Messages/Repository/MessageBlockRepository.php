<?php

declare(strict_types=1);

namespace Modules\Messages\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Messages\Entity\MessageBlock;

/**
 * @extends ServiceEntityRepository<MessageBlock>
 */
final class MessageBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageBlock::class);
    }

    public function findBetween(User $blocker, User $blocked): ?MessageBlock
    {
        return $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    public function isBlockedEitherWay(User $a, User $b): bool
    {
        $count = (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('(b.blocker = :a AND b.blocked = :b) OR (b.blocker = :b AND b.blocked = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return list<MessageBlock>
     */
    public function listFor(User $blocker): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.blocker = :blocker')
            ->setParameter('blocker', $blocker)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
