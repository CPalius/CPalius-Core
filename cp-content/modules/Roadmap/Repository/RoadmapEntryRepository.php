<?php

declare(strict_types=1);

namespace Modules\Roadmap\Repository;

use Modules\Roadmap\Entity\RoadmapEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoadmapEntry>
 */
class RoadmapEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoadmapEntry::class);
    }

    public function findOneBySlugAndLocale(string $slug, string $locale): ?RoadmapEntry
    {
        return $this->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /**
     * @return list<RoadmapEntry>
     */
    public function findAdminList(?string $locale = null, ?string $status = null, ?string $kind = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.sortOrder', 'ASC')
            ->addOrderBy('e.publishedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if ($locale !== null && $locale !== '') {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }
        if ($status !== null && $status !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }
        if ($kind !== null && $kind !== '') {
            $qb->andWhere('e.kind = :kind')->setParameter('kind', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    public function createPublicFeedQueryBuilder(string $locale, ?string $status = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.locale = :locale')
            ->andWhere('e.status != :cancelled')
            ->andWhere('e.publishedAt IS NOT NULL')
            // Naive DATETIME vs PHP UTC now would hide "future" local rows; use DB CURRENT_TIMESTAMP().
            ->andWhere('e.publishedAt <= CURRENT_TIMESTAMP()')
            ->setParameter('locale', $locale)
            ->setParameter('cancelled', RoadmapEntry::STATUS_CANCELLED)
            ->orderBy('e.publishedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }

    /**
     * Milestone strip: sortOrder ASC, featured first.
     *
     * @return list<RoadmapEntry>
     */
    public function findPublicMilestones(string $locale, ?string $status = null, int $limit = 12): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.locale = :locale')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status != :cancelled')
            ->andWhere('e.publishedAt IS NOT NULL')
            ->andWhere('e.publishedAt <= CURRENT_TIMESTAMP()')
            ->setParameter('locale', $locale)
            ->setParameter('kind', RoadmapEntry::KIND_MILESTONE)
            ->setParameter('cancelled', RoadmapEntry::STATUS_CANCELLED)
            ->orderBy('e.isFeatured', 'DESC')
            ->addOrderBy('e.sortOrder', 'ASC')
            ->addOrderBy('e.publishedAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * All native Studio rows (milestone + update) for the right column.
     *
     * @return list<RoadmapEntry>
     */
    public function findPublicNativeEntries(string $locale, ?string $status = null, int $limit = 40): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.locale = :locale')
            ->andWhere('e.status != :cancelled')
            ->andWhere('e.publishedAt IS NOT NULL')
            ->andWhere('e.publishedAt <= CURRENT_TIMESTAMP()')
            ->setParameter('locale', $locale)
            ->setParameter('cancelled', RoadmapEntry::STATUS_CANCELLED)
            ->orderBy('e.isFeatured', 'DESC')
            ->addOrderBy('e.sortOrder', 'ASC')
            ->addOrderBy('e.publishedAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<RoadmapEntry>
     */
    public function findPublicUpdates(string $locale, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.locale = :locale')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status != :cancelled')
            ->andWhere('e.publishedAt IS NOT NULL')
            ->andWhere('e.publishedAt <= CURRENT_TIMESTAMP()')
            ->setParameter('locale', $locale)
            ->setParameter('kind', RoadmapEntry::KIND_UPDATE)
            ->setParameter('cancelled', RoadmapEntry::STATUS_CANCELLED)
            ->orderBy('e.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countRecentPublic(string $locale, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.locale = :locale')
            ->andWhere('e.status != :cancelled')
            ->andWhere('e.publishedAt IS NOT NULL')
            ->andWhere('e.publishedAt >= :since')
            ->andWhere('e.publishedAt <= CURRENT_TIMESTAMP()')
            ->setParameter('locale', $locale)
            ->setParameter('cancelled', RoadmapEntry::STATUS_CANCELLED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
