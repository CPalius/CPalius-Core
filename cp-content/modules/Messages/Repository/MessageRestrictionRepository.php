<?php

declare(strict_types=1);

namespace Modules\Messages\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Messages\Entity\MessageRestriction;

/**
 * @extends ServiceEntityRepository<MessageRestriction>
 */
final class MessageRestrictionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageRestriction::class);
    }

    public function findForUser(User $user): ?MessageRestriction
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function createActiveQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.revokedAt IS NULL')
            ->andWhere('r.expiresAt IS NULL OR r.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('r.createdAt', 'DESC');
    }

    public function countActive(): int
    {
        return (int) $this->createActiveQueryBuilder()
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
