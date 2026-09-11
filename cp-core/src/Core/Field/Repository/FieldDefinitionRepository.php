<?php

declare(strict_types=1);

namespace App\Core\Field\Repository;

use App\Core\Field\Entity\FieldDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FieldDefinition>
 */
class FieldDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FieldDefinition::class);
    }

    /**
     * @return list<FieldDefinition>
     */
    public function findByBundle(string $bundle): array
    {
        /** @var list<FieldDefinition> $rows */
        $rows = $this->createQueryBuilder('f')
            ->andWhere('f.bundle = :bundle')
            ->setParameter('bundle', $bundle)
            ->orderBy('f.weight', 'ASC')
            ->addOrderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneByBundleAndName(string $bundle, string $name): ?FieldDefinition
    {
        return $this->findOneBy(['bundle' => $bundle, 'name' => $name]);
    }

    /**
     * @return list<string>
     */
    public function distinctBundles(): array
    {
        /** @var list<array{bundle: string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('DISTINCT f.bundle AS bundle')
            ->orderBy('f.bundle', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['bundle'], $rows);
    }

    /**
     * Bundle => field count, for the AACP index.
     *
     * @return array<string, int>
     */
    public function countPerBundle(): array
    {
        /** @var list<array{bundle: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.bundle AS bundle', 'COUNT(f.id) AS total')
            ->groupBy('f.bundle')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['bundle']] = (int) $row['total'];
        }

        return $out;
    }
}
