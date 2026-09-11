<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

use App\Core\TextFormat\Entity\TextFormat;
use App\Core\TextFormat\Repository\TextFormatRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotent seed of YAML catalog rows into cp_text_formats. Never overwrites
 * an operator-edited row (VocabularySeeder / PathAliasPatternSeeder pattern).
 */
final class TextFormatSeeder
{
    public function __construct(
        private readonly TextFormatRepository $repository,
        private readonly TextFormatRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function ensureCatalog(): int
    {
        $created = 0;

        foreach ($this->registry->all() as $resolved) {
            if ($this->repository->findOneByMachineName($resolved->id) !== null) {
                continue;
            }

            $row = new TextFormat($resolved->id, $resolved->label);
            $row->setDescription($resolved->description)
                ->setWysiwyg($resolved->wysiwyg)
                ->setFilters($resolved->filters)
                ->setLocked(true);
            $this->entityManager->persist($row);
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
            $this->registry->invalidate();
        }

        return $created;
    }
}
