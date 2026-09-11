<?php

declare(strict_types=1);

namespace App\Core\Taxonomy;

use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotent vocabulary creation for module installers (FieldDefinitionSeeder
 * pattern). "ensure" only ever CREATES a missing vocabulary — it never
 * overwrites one an operator may have edited from /aacp/taxonomy.
 */
final class VocabularySeeder
{
    public function __construct(
        private readonly VocabularyRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly VocabularyRegistry $registry,
    ) {
    }

    /**
     * @param list<array{machine_name: string, label: string, description?: ?string, hierarchical?: bool, weight?: int}> $specs
     *
     * @return int number of vocabularies created
     */
    public function ensure(array $specs): int
    {
        $created = 0;

        foreach ($specs as $spec) {
            $machineName = trim((string) ($spec['machine_name'] ?? ''));
            $label = trim((string) ($spec['label'] ?? ''));

            if (preg_match(Vocabulary::MACHINE_NAME_PATTERN, $machineName) !== 1 || $label === '') {
                continue;
            }
            if ($this->repository->findOneByMachineName($machineName) !== null) {
                continue;
            }

            $vocabulary = new Vocabulary($machineName, $label);
            $vocabulary
                ->setDescription($spec['description'] ?? null)
                ->setHierarchical((bool) ($spec['hierarchical'] ?? true))
                ->setWeight((int) ($spec['weight'] ?? 0));

            $this->entityManager->persist($vocabulary);
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
            $this->registry->invalidate();
        }

        return $created;
    }
}
