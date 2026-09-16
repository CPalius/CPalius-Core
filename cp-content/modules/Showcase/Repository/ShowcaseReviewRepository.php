<?php

declare(strict_types=1);

namespace Modules\Showcase\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseReview;

/**
 * @extends ServiceEntityRepository<ShowcaseReview>
 */
final class ShowcaseReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShowcaseReview::class);
    }

    public function createApprovedQueryBuilder(ShowcaseItem $item): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->andWhere('r.item = :item')
            ->andWhere('r.status = :status')
            ->setParameter('item', $item)
            ->setParameter('status', ShowcaseReview::STATUS_APPROVED)
            ->orderBy('r.createdAt', 'DESC');
    }

    public function findOneByItemAndAuthor(ShowcaseItem $item, User $author): ?ShowcaseReview
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.item = :item')
            ->andWhere('r.author = :author')
            ->setParameter('item', $item)
            ->setParameter('author', $author)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Rating sum and count over APPROVED reviews only. The item's aggregate is
     * recomputed from this rather than incremented, so moderating a review after
     * the fact corrects the average instead of leaving it skewed.
     *
     * @return array{sum: int, count: int}
     */
    public function aggregateApproved(ShowcaseItem $item): array
    {
        /** @var array{sum: string|int|null, count: string|int|null} $row */
        $row = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.rating), 0) AS sum, COUNT(r.id) AS count')
            ->andWhere('r.item = :item')
            ->andWhere('r.status = :status')
            ->setParameter('item', $item)
            ->setParameter('status', ShowcaseReview::STATUS_APPROVED)
            ->getQuery()
            ->getSingleResult();

        return ['sum' => (int) ($row['sum'] ?? 0), 'count' => (int) ($row['count'] ?? 0)];
    }

    public function createPendingQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.item', 'i')->addSelect('i')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->andWhere('r.status = :status')
            ->setParameter('status', ShowcaseReview::STATUS_PENDING)
            ->orderBy('r.createdAt', 'ASC');
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :status')
            ->setParameter('status', ShowcaseReview::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
