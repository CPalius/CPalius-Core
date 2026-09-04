<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Modules\Forum\Entity\ForumPresence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPresence>
 */
final class ForumPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPresence::class);
    }

    public function findOneBySessionHash(string $sessionHash): ?ForumPresence
    {
        return $this->findOneBy(['sessionHash' => $sessionHash]);
    }

    /**
     * @return list<ForumPresence>
     */
    public function findActiveMembers(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->andWhere('p.user IS NOT NULL')
            ->andWhere('p.lastSeenAt >= :since')
            ->andWhere('u.status = :status')
            ->setParameter('since', $since)
            ->setParameter('status', User::STATUS_ACTIVE)
            ->orderBy('p.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveGuests(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user IS NULL')
            ->andWhere('p.lastSeenAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeStale(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.lastSeenAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
