<?php

namespace App\Repository;

use App\Entity\PerformanceBackendStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerformanceBackendStatus>
 */
class PerformanceBackendStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerformanceBackendStatus::class);
    }

    public function findOneByBackendId(string $backendId): ?PerformanceBackendStatus
    {
        return $this->findOneBy(['backendId' => $backendId]);
    }

    /**
     * Dashboard widget'larının (bkz. Core/Aacp/Widgets/*) ve Performans
     * sayfasının ihtiyaç duyduğu tüm durum satırlarını TEK sorguda okur
     * (Manifesto Law 6.1 ruhu — SettingRepository::findAllAsMap() ile aynı
     * desen).
     *
     * @return array<string, PerformanceBackendStatus>
     */
    public function findAllAsMap(): array
    {
        $rows = $this->findAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getBackendId()] = $row;
        }

        return $map;
    }
}
