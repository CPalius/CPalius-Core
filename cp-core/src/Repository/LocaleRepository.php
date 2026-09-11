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
     * Active locales for forms and the front-end switcher, ordered by sortOrder.
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
     * Total locale count for dashboard gauge denominator.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Active locale count (COUNT only, for dashboard gauge numerator).
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
