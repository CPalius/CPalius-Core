<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumUserStats;

/** @extends ServiceEntityRepository<ForumUserStats> */
final class ForumUserStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumUserStats::class);
    }

    public function findOneByUser(User $user): ?ForumUserStats
    {
        return $this->findOneBy(['user' => $user]);
    }
}
