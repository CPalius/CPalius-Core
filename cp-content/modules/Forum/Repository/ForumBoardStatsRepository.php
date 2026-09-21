<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumBoardStats;

/** @extends ServiceEntityRepository<ForumBoardStats> */
final class ForumBoardStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumBoardStats::class);
    }

    public function findOneByLocale(string $locale): ?ForumBoardStats
    {
        return $this->find($locale);
    }
}
