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

    /** @var array<string, int|null> machine name => id, so a repeat lookup is an identity-map hit, not a query */
    private array $idByMachineName = [];

    public function findOneByMachineName(string $machineName): ?Vocabulary
    {
        // A page asks for the same few vocabularies (tags, categories) many times; one request measured
        // five identical SELECTs for one of them. Remember only the id: find() answers from the entity
        // manager's identity map, and still queries again if the map was cleared.
        if (\array_key_exists($machineName, $this->idByMachineName)) {
            $id = $this->idByMachineName[$machineName];

            return $id === null ? null : $this->find($id);
        }

        $vocabulary = $this->findOneBy(['machineName' => $machineName]);
        $this->idByMachineName[$machineName] = $vocabulary?->getId();

        return $vocabulary;
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
