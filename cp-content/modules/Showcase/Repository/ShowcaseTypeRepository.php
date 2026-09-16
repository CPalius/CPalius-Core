<?php

declare(strict_types=1);

namespace Modules\Showcase\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Showcase\Entity\ShowcaseType;

/**
 * @extends ServiceEntityRepository<ShowcaseType>
 */
final class ShowcaseTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShowcaseType::class);
    }

    /**
     * @return list<ShowcaseType>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tr')->addSelect('tr')
            ->orderBy('t.weight', 'ASC')
            ->addOrderBy('t.machineName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Enabled types only — what the public listing and the submit form offer.
     *
     * @return list<ShowcaseType>
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tr')->addSelect('tr')
            ->andWhere('t.enabled = true')
            ->orderBy('t.weight', 'ASC')
            ->addOrderBy('t.machineName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByMachineName(string $machineName): ?ShowcaseType
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.translations', 'tr')->addSelect('tr')
            ->andWhere('t.machineName = :machineName')
            ->setParameter('machineName', $machineName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
