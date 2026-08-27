<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumPost;
use App\Entity\ForumPostReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPostReport>
 */
final class ForumPostReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostReport::class);
    }

    /**
     * @return ForumPostReport[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->setParameter('status', ForumPostReport::STATUS_OPEN)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :status')
            ->setParameter('status', ForumPostReport::STATUS_OPEN)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasOpenReportFrom(ForumPost $post, ?int $reporterId): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.post = :post')
            ->andWhere('r.status = :status')
            ->setParameter('post', $post)
            ->setParameter('status', ForumPostReport::STATUS_OPEN);

        if ($reporterId !== null) {
            $qb->andWhere('r.reporter = :reporter')->setParameter('reporter', $reporterId);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }
}
