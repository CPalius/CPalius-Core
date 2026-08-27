<?php

namespace App\Repository;

use App\Entity\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Locale>
 */
class LocaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Locale::class);
    }

    /**
     * Aktif diller: içerik formundaki dil sekmelerinin ve ön yüzdeki dil
     * değiştiricinin tek veri kaynağı. sortOrder'a göre sıralı döner.
     *
     * @return list<Locale>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.isActive = true')
            ->orderBy('l.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefault(): ?Locale
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.isDefault = true')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * AACP Dashboard "Aktif Diller" gauge'unun payda değeri (toplam dil).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Aynı gauge'un pay değeri — findActive()'in tam entity hydration'ı
     * yerine tek bir COUNT sorgusu (dashboard'un tek ihtiyacı sayı).
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.code = :code')
            ->setParameter('code', $code);

        if ($excludeId !== null) {
            $qb->andWhere('l.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
