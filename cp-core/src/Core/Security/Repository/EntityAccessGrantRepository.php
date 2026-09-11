<?php

declare(strict_types=1);

namespace App\Core\Security\Repository;

use App\Core\Security\Entity\EntityAccessGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntityAccessGrant>
 */
class EntityAccessGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntityAccessGrant::class);
    }

    public function findOneMatching(
        string $entityType,
        int $entityId,
        string $capability,
        string $subjectType,
        string $subjectId,
    ): ?EntityAccessGrant {
        return $this->findOneBy([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'capability' => $capability,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
        ]);
    }

    /**
     * @return list<EntityAccessGrant>
     */
    public function findForEntity(string $entityType, int $entityId): array
    {
        /** @var list<EntityAccessGrant> $rows */
        $rows = $this->findBy(['entityType' => $entityType, 'entityId' => $entityId], ['id' => 'ASC']);

        return $rows;
    }
}
