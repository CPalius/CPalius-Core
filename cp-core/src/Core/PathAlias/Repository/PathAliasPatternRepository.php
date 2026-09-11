<?php

declare(strict_types=1);

namespace App\Core\PathAlias\Repository;

use App\Core\PathAlias\Entity\PathAliasPattern;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PathAliasPattern>
 */
class PathAliasPatternRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PathAliasPattern::class);
    }

    public function findOneByTypeAndBundle(string $entityType, string $bundle): ?PathAliasPattern
    {
        return $this->findOneBy(['entityType' => $entityType, 'bundle' => $bundle]);
    }

    /**
     * @return list<PathAliasPattern>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.entityType', 'ASC')
            ->addOrderBy('p.bundle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function distinctBundles(string $entityType = 'node'): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.bundle')
            ->andWhere('p.entityType = :entityType')
            ->setParameter('entityType', $entityType)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'bundle');
    }
}
