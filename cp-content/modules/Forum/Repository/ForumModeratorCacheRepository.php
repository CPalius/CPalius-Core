<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumModeratorCache;
use Modules\Forum\Entity\ForumSection;

/** @extends ServiceEntityRepository<ForumModeratorCache> */
final class ForumModeratorCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumModeratorCache::class);
    }

    /**
     * @return list<ForumModeratorCache>
     */
    public function findForSectionOnIndex(ForumSection $section): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.section = :section')
            ->andWhere('c.displayOnIndex = true')
            ->setParameter('section', $section)
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
