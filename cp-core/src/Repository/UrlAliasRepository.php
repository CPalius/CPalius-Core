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
     * UrlAliasListener'ın tek sorgusu: gelen isteğin normalize edilmiş
     * path'ine + locale'ine göre aktif bir alias arar. Bulunamazsa null
     * döner ve listener orijinal 404'ü olduğu gibi bırakır (fail-safe).
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
     * Belirli bir yolun bu locale'de zaten kullanımda olup olmadığını
     * kontrol eder (AACP CRUD formundaki benzersizlik doğrulaması için).
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
