<?php

declare(strict_types=1);

namespace App\Core\PathAlias;

use App\Core\PathAlias\Entity\PathAliasPattern;
use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotent pattern creation for module installers / first-run defaults
 * (VocabularySeeder pattern). "ensure" only ever CREATES a missing row — it
 * never overwrites one an operator may have edited from /aacp/path-patterns.
 */
final class PathAliasPatternSeeder
{
    public function __construct(
        private readonly PathAliasPatternRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{entity_type?: string, bundle: string, pattern: string, enabled?: bool}> $specs
     *
     * @return int number of patterns created
     */
    public function ensure(array $specs): int
    {
        $created = 0;

        foreach ($specs as $spec) {
            $entityType = trim((string) ($spec['entity_type'] ?? 'node'));
            $bundle = trim((string) ($spec['bundle'] ?? ''));
            $pattern = trim((string) ($spec['pattern'] ?? ''));

            if ($entityType === '' || $bundle === '' || $pattern === '') {
                continue;
            }
            if ($this->repository->findOneByTypeAndBundle($entityType, $bundle) !== null) {
                continue;
            }

            $row = new PathAliasPattern($entityType, $bundle, $pattern);
            $row->setEnabled((bool) ($spec['enabled'] ?? true));
            $this->entityManager->persist($row);
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }
}
