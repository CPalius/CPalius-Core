<?php

declare(strict_types=1);

namespace App\Core\Security\Repository;

use App\Core\Security\Entity\UserCapabilityOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserCapabilityOverride> */
final class UserCapabilityOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCapabilityOverride::class);
    }

    /**
     * @return list<UserCapabilityOverride>
     */
    public function findAllForUser(int $userId): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('o.capability', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
