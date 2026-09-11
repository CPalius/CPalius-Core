<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Entity\ForumSection;

/**
 * @extends ServiceEntityRepository<ForumNodePermission>
 */
final class ForumNodePermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumNodePermission::class);
    }

    /**
     * @return list<ForumNodePermission>
     */
    public function findAllForLocale(string $locale): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.section', 's')->addSelect('s')
            ->andWhere('s.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ForumNodePermission>
     */
    public function findForSection(ForumSection $section): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getResult();
    }

    public function deleteForSection(ForumSection $section): void
    {
        $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->execute();
    }
}
