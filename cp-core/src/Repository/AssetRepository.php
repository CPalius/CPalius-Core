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
     * Aynı içerik hash'ine sahip var olan bir Asset'i bulur.
     * AssetManager::upload() bunu kullanarak aynı dosyanın tekrar tekrar
     * fiziksel olarak depolanmasını ve veritabanında çoğaltılmasını önler.
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
     * AACP Dashboard "Medya Dosyaları" kartı için toplam sayı.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dashboard'daki "Disk Kullanımı" gauge'unun veri kaynağı, byte
     * cinsinden. COALESCE ile boş tabloda NULL yerine 0 döner.
     */
    public function sumFileSize(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.fileSize), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dashboard'daki "Mime Tipi Dağılımı" stacked-bar widget'ının veri
     * kaynağı — idx_asset_mime_type index'i üzerinden. En çok kullanılan
     * $limit mime tipini döner; "diğer" bucket'lama işi (limit'i aşan
     * kalan kısmın toplanması) bilinçli olarak repository'de DEĞİL,
     * controller katmanında yapılır (repository saf veri döner).
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
