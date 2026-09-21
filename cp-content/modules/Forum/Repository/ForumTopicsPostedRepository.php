<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicsPosted;

/** @extends ServiceEntityRepository<ForumTopicsPosted> */
final class ForumTopicsPostedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopicsPosted::class);
    }

    public function findOne(User $user, ForumTopic $topic): ?ForumTopicsPosted
    {
        return $this->findOneBy(['user' => $user, 'topic' => $topic]);
    }
}
