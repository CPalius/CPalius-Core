<?php

declare(strict_types=1);

namespace App\Core\Trash\Repository;

use App\Core\Trash\Entity\TrashRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrashRule>
 */
class TrashRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrashRule::class);
    }
}
