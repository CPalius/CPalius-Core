<?php

namespace Modules\Menu\Repository;

use Modules\Menu\Entity\Menu;
use Modules\Menu\Entity\MenuItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MenuItem>
 */
class MenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItem::class);
    }

    /**
     * Load every item of the menu in one query; FrontMenuRuntime builds the tree in memory.
     *
     * @return list<MenuItem>
     */
    public function findAllByMenuAndLocale(Menu $menu, string $locale): array
    {
        return $this->createQueryBuilder('mi')
            ->andWhere('mi.menu = :menu')
            ->andWhere('mi.locale = :locale')
            ->setParameter('menu', $menu)
            ->setParameter('locale', $locale)
            ->orderBy('mi.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Siblings under the same parent in one locale — used to append sortOrder.
     *
     * @return list<MenuItem>
     */
    public function findByMenuParentAndLocale(Menu $menu, ?int $parentId, string $locale): array
    {
        $qb = $this->createQueryBuilder('mi')
            ->andWhere('mi.menu = :menu')
            ->andWhere('mi.locale = :locale')
            ->setParameter('menu', $menu)
            ->setParameter('locale', $locale)
            ->orderBy('mi.sortOrder', 'ASC');

        if ($parentId === null) {
            $qb->andWhere('mi.parent IS NULL');
        } else {
            $qb->andWhere('IDENTITY(mi.parent) = :parentId')
                ->setParameter('parentId', $parentId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Full hierarchy for admin (all locales, no locale filter).
     *
     * @return list<MenuItem>
     */
    public function findAllByMenu(Menu $menu): array
    {
        return $this->createQueryBuilder('mi')
            ->andWhere('mi.menu = :menu')
            ->setParameter('menu', $menu)
            ->orderBy('mi.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
