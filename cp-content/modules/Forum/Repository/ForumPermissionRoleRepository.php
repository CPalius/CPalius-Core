<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPermissionRole;

/** @extends ServiceEntityRepository<ForumPermissionRole> */
final class ForumPermissionRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPermissionRole::class);
    }

    public function findOneByCode(string $code): ?ForumPermissionRole
    {
        return $this->findOneBy(['code' => $code]);
    }
}
