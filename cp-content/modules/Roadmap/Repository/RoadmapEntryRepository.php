<?php

declare(strict_types=1);

namespace Modules\Roadmap\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Roadmap\Entity\RoadmapEntry;
use Symfony\Component\Uid\Uuid;

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
     * Any publicly visible row with this slug (locale-agnostic). Used to resolve a missing translation.
     */
    public function findOnePublicBySlug(string $slug): ?RoadmapEntry
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.slug = :slug')
            ->andWhere('e.status != :cancelled')
            ->andWhere('e.publishedAt IS NOT NULL')
            ->andWhere('e.publishedAt <= CURRENT_TIMESTAMP()')
            ->setParameter('slug', $slug)
            ->setParameter('cancelled', RoadmapEntry::STATUS_CANCELLED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findTranslation(Uuid $groupId, string $locale): ?RoadmapEntry
    {
        return $this->findOneBy(['translationGroupId' => $groupId, 'locale' => $locale]);
    }

    public function slugExists(string $slug, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.slug = :slug')
            ->andWhere('e.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere('e.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
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

    /**
     * Title/summary match on publicly visible rows (same visibility as the feed).
     *
     * @return list<RoadmapEntry>
     */
    public function searchPublic(string $term, string $locale, int $limit): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return $this->createPublicFeedQueryBuilder($locale)
            ->andWhere('(e.title LIKE :term OR e.summary LIKE :term)')
            ->setParameter('term', '%'.addcslashes($term, '%_').'%')
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
