<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumUserBlock;

/** @extends ServiceEntityRepository<ForumUserBlock> */
final class ForumUserBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumUserBlock::class);
    }

    /**
     * @return list<ForumUserBlock>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'DESC']);
    }

    public function findOne(User $user, User $blocked): ?ForumUserBlock
    {
        return $this->findOneBy(['user' => $user, 'blocked' => $blocked]);
    }
}
