<?php

declare(strict_types=1);

namespace App\Core\Api\Repository;

use App\Core\Api\Entity\ApiIdempotency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiIdempotency>
 */
class ApiIdempotencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiIdempotency::class);
    }

    public function findValid(string $scopeHash, \DateTimeImmutable $now): ?ApiIdempotency
    {
        /** @var ApiIdempotency|null $row */
        $row = $this->createQueryBuilder('i')
            ->andWhere('i.scopeHash = :hash')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('hash', $scopeHash)
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('i')
            ->delete()
            ->andWhere('i.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
