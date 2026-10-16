<?php

declare(strict_types=1);

namespace App\Core\Trash\Repository;

use App\Core\Trash\Entity\TrashItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrashItem>
 */
class TrashItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrashItem::class);
    }

    /**
     * @param array{type?: ?string, q?: ?string} $filters
     */
    public function createFilteredQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.deletedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if (!empty($filters['type'])) {
            $qb->andWhere('t.resourceName = :type')->setParameter('type', $filters['type']);
        }

        if (!empty($filters['q'])) {
            $qb->andWhere('t.label LIKE :q OR t.resourceId = :qExact')
                ->setParameter('q', '%'.addcslashes((string) $filters['q'], '%_\\').'%')
                ->setParameter('qExact', (string) $filters['q']);
        }

        return $qb;
    }

    /**
     * Items deleted in the same flush, parents first (children were captured
     * after the row that owned them, so ascending id is the restore order).
     *
     * @return list<TrashItem>
     */
    public function findBatch(string $batchId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.batchId = :b')
            ->setParameter('b', $batchId)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Item count per resource type, for the filter dropdown.
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.resourceName AS name, COUNT(t.id) AS cnt')
            ->groupBy('t.resourceName')
            ->orderBy('t.resourceName', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (int) $row['cnt'];
        }

        return $out;
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.expiresAt IS NOT NULL')
            ->andWhere('t.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }

    public function purgeAll(?string $type = null): int
    {
        $qb = $this->createQueryBuilder('t')->delete();
        if ($type !== null && $type !== '') {
            $qb->andWhere('t.resourceName = :type')->setParameter('type', $type);
        }

        return (int) $qb->getQuery()->execute();
    }
}
