<?php

declare(strict_types=1);

namespace App\Core\Revision\Repository;

use App\Core\Revision\Entity\NodeRevision;
use App\Entity\Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NodeRevision>
 */
class NodeRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeRevision::class);
    }

    /**
     * @return list<NodeRevision> newest first
     */
    public function findByNode(Node $node, int $limit = 100): array
    {
        /** @var list<NodeRevision> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.node = :node')->setParameter('node', $node)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }

    public function latestForNode(Node $node): ?NodeRevision
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.node = :node')->setParameter('node', $node)
            ->orderBy('r.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function countForNode(Node $node): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.node = :node')->setParameter('node', $node)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Delete all but the newest $keep revisions of a node. Returns rows removed.
     */
    public function pruneNode(Node $node, int $keep): int
    {
        $keep = max(1, $keep);

        /** @var list<int> $ids */
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->createQueryBuilder('r')
                ->select('r.id')
                ->andWhere('r.node = :node')->setParameter('node', $node)
                ->orderBy('r.id', 'DESC')
                ->setFirstResult($keep)
                ->getQuery()->getArrayResult(),
        );

        if ($ids === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
    }

    /**
     * Node ids that currently exceed $keep revisions (for the prune cron).
     *
     * @return list<int>
     */
    public function nodeIdsOverLimit(int $keep, int $limit = 200): array
    {
        /** @var list<array{node_id: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.node) AS node_id')
            ->groupBy('r.node')
            ->having('COUNT(r.id) > :keep')->setParameter('keep', max(1, $keep))
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['node_id'], $rows);
    }
}
