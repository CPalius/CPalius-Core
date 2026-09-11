<?php

declare(strict_types=1);

namespace App\Core\Taxonomy\Repository;

use App\Core\Taxonomy\Entity\Vocabulary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vocabulary>
 */
class VocabularyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vocabulary::class);
    }

    public function findOneByMachineName(string $machineName): ?Vocabulary
    {
        return $this->findOneBy(['machineName' => $machineName]);
    }

    /**
     * @return list<Vocabulary> ordered by weight then label
     */
    public function findAllOrdered(): array
    {
        /** @var list<Vocabulary> $rows */
        $rows = $this->createQueryBuilder('v')
            ->orderBy('v.weight', 'ASC')
            ->addOrderBy('v.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
