<?php

declare(strict_types=1);

namespace Modules\Seo\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Seo\Entity\SeoRedirect;

/** @extends ServiceEntityRepository<SeoRedirect> */
final class SeoRedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoRedirect::class);
    }

    public function findOneActiveBySource(string $sourcePath): ?SeoRedirect
    {
        return $this->findOneActiveBySources([$sourcePath]);
    }

    /**
     * @param list<string> $sourcePaths
     */
    public function findOneActiveBySources(array $sourcePaths): ?SeoRedirect
    {
        $sourcePaths = array_values(array_unique(array_filter($sourcePaths, static fn (string $p): bool => $p !== '')));
        if ($sourcePaths === []) {
            return null;
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.sourcePath IN (:paths)')
            ->andWhere('r.isActive = true')
            ->setParameter('paths', $sourcePaths, ArrayParameterType::STRING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function sourceTaken(string $sourcePath, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.sourcePath = :path')
            ->setParameter('path', $sourcePath);

        if ($exceptId !== null) {
            $qb->andWhere('r.id != :id')->setParameter('id', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return list<SeoRedirect>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.sourcePath', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
