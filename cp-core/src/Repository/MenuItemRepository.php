<?php

namespace App\Repository;

use App\Entity\Menu;
use App\Entity\MenuItem;
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
     * TEK sorguda menünün TÜM öğelerini (her seviye dahil) çeker —
     * FrontMenuRuntime::buildTree() bunu recursive DB çağrısı yerine
     * bellek içi parent_id gruplamasıyla ağaca çevirir (bkz. o sınıfın
     * docblock'u: önceden her menü seviyesi için ayrı bir sorgu atılıyordu,
     * "menu_items" tablosuna 11+ sorgu dev-mode N+1 guard'ını tetikliyordu).
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
     * Admin ekranında tüm hiyerarşiyi (tüm locale'ler dahil) tek seferde
     * çekmek için — FrontMenuRuntime'ın aksine burada locale filtresi yok,
     * yönetici tüm dillerdeki öğeleri aynı ağaçta görebilmeli.
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
