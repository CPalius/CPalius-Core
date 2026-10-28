<?php

declare(strict_types=1);

namespace Modules\Menu\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Menu\Entity\Menu;

/**
 * @extends ServiceEntityRepository<Menu>
 */
class MenuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Menu::class);
    }

    public function findOneByIdentifier(string $identifier): ?Menu
    {
        return $this->findOneBy(['identifier' => $identifier]);
    }

    public function findOneByLocation(string $location): ?Menu
    {
        return $this->findOneBy(['location' => $location]);
    }

    /**
     * Gives the location to one menu: it is taken from any other menu first (the column is unique).
     */
    public function assignLocation(Menu $menu, ?string $location): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $menu, $location): void {
            if ($location !== null && $location !== '') {
                $em->getConnection()->executeStatement(
                    'UPDATE cp_menu_menus SET location = NULL WHERE location = ? AND id <> ?',
                    [$location, $menu->getId()],
                );
            }
            $menu->setLocation($location);
            $em->flush();
        });
    }
}
