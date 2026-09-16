<?php

declare(strict_types=1);

namespace Modules\Messages\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Messages\Entity\MessageParticipant;

/**
 * @extends ServiceEntityRepository<MessageParticipant>
 */
final class MessageParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageParticipant::class);
    }

    public function unreadTotal(User $user): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.unreadCount), 0)')
            ->andWhere('p.user = :user')
            ->andWhere('p.hidden = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sharesThread(User $a, User $b): bool
    {
        $count = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.thread', 't')
            ->innerJoin('t.participants', 'other')
            ->andWhere('p.user = :a')
            ->andWhere('other.user = :b')
            ->andWhere('p.hidden = false')
            ->andWhere('other.hidden = false')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
