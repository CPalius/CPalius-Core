<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Repository;

use App\Core\TextFormat\Entity\TextFormat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TextFormat>
 */
class TextFormatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TextFormat::class);
    }

    public function findOneByMachineName(string $machineName): ?TextFormat
    {
        return $this->findOneBy(['machineName' => $machineName]);
    }

    /**
     * @return list<TextFormat>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.machineName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
