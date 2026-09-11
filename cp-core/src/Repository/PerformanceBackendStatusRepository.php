<?php

declare(strict_types=1);

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
     * All backend status rows in one query (Law 6.1 map pattern).
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
