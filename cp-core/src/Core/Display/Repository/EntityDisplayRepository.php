<?php

declare(strict_types=1);

namespace App\Core\Display\Repository;

use App\Core\Display\Entity\EntityDisplay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntityDisplay>
 */
class EntityDisplayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntityDisplay::class);
    }

    /**
     * @return list<EntityDisplay>
     */
    public function findByBundleAndViewMode(string $bundle, string $viewMode): array
    {
        /** @var list<EntityDisplay> $rows */
        $rows = $this->findBy(['bundle' => $bundle, 'viewMode' => $viewMode]);

        return $rows;
    }

    /**
     * @return list<EntityDisplay>
     */
    public function findByBundle(string $bundle): array
    {
        /** @var list<EntityDisplay> $rows */
        $rows = $this->findBy(['bundle' => $bundle]);

        return $rows;
    }

    public function findOneByBundleViewModeAndField(string $bundle, string $viewMode, string $fieldName): ?EntityDisplay
    {
        return $this->findOneBy(['bundle' => $bundle, 'viewMode' => $viewMode, 'fieldName' => $fieldName]);
    }

    /**
     * @return list<string>
     */
    public function distinctBundles(): array
    {
        /** @var list<array{bundle: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.bundle AS bundle')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['bundle'], $rows);
    }
}
