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
}
