<?php

namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Asset>
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    /**
     * Find asset by content hash for upload deduplication.
     */
    public function findOneByHash(string $hash): ?Asset
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.hash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Total asset count for dashboard card.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Total file size in bytes for dashboard disk gauge (COALESCE to 0).
     */
    public function sumFileSize(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.fileSize), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Top mime types by count for dashboard chart; "other" bucketing is in controller.
     *
     * @return list<array{mimeType: string, count: int}>
     */
    public function countGroupedByMimeType(int $limit = 6): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.mimeType AS mimeType, COUNT(a.id) AS count')
            ->groupBy('a.mimeType')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['mimeType' => $row['mimeType'], 'count' => (int) $row['count']], $rows);
    }
}
