<?php

namespace Modules\Menu\Repository;

use Modules\Menu\Entity\Menu;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
}
