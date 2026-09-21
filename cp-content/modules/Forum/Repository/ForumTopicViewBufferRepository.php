<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumTopicViewBuffer;

/** @extends ServiceEntityRepository<ForumTopicViewBuffer> */
final class ForumTopicViewBufferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopicViewBuffer::class);
    }
}
