<?php

namespace App\Repository;

use App\Entity\UrlAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UrlAlias>
 */
class UrlAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UrlAlias::class);
    }

    /**
     * UrlAliasListener lookup by normalized path + locale; null preserves original 404.
     */
    public function findOneActiveByPathAndLocale(string $aliasPath, string $locale): ?UrlAlias
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.aliasPath = :aliasPath')
            ->andWhere('a.locale = :locale')
            ->andWhere('a.isActive = true')
            ->setParameter('aliasPath', $aliasPath)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whether alias path is taken for locale (AACP form uniqueness check).
     */
    public function pathExists(string $aliasPath, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.aliasPath = :aliasPath')
            ->andWhere('a.locale = :locale')
            ->setParameter('aliasPath', $aliasPath)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere('a.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @return list<UrlAlias>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.aliasPath', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
