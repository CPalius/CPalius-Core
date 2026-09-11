<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumTopicPrefix;

/**
 * @extends ServiceEntityRepository<ForumTopicPrefix>
 */
final class ForumTopicPrefixRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopicPrefix::class);
    }

    /** @return ForumTopicPrefix[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
