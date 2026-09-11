<?php

declare(strict_types=1);

namespace App\Core\Config\Provider;

use App\Core\Config\ConfigProviderInterface;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use App\Core\Taxonomy\VocabularyRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document per vocabulary: taxonomy.{machine_name}. Vocabularies are
 * structure; their terms are content and are never exported or removed here.
 *
 * Upsert-only — unlike FieldConfigProvider, import never deletes a vocabulary
 * missing from disk, because that would cascade-delete its terms (real content).
 * Deleting a vocabulary is a deliberate AACP action, guarded there against
 * non-empty vocabularies.
 */
final class TaxonomyConfigProvider implements ConfigProviderInterface
{
    private const PREFIX = 'taxonomy.';

    public function __construct(
        private readonly VocabularyRepository $repository,
        private readonly VocabularyRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function documents(): array
    {
        return array_map(
            static fn (Vocabulary $v): string => self::PREFIX.$v->getMachineName(),
            $this->repository->findAllOrdered(),
        );
    }

    public function ownsDocument(string $name): bool
    {
        return str_starts_with($name, self::PREFIX) && $this->machineNameOf($name) !== '';
    }

    public function exportDocument(string $name): array
    {
        $vocabulary = $this->repository->findOneByMachineName($this->machineNameOf($name));
        if ($vocabulary === null) {
            return [];
        }

        $row = $vocabulary->toArray();
        unset($row['machine_name']);

        return $row;
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $machineName = $this->machineNameOf($name);
        $vocabulary = $this->repository->findOneByMachineName($machineName);

        if ($vocabulary === null) {
            return [sprintf('+ taxonomy.%s', $machineName)];
        }

        $live = $vocabulary->toArray();
        unset($live['machine_name']);
        ksort($live);

        $normalized = $this->normalizeIncoming($incoming);

        return $live !== $normalized ? [sprintf('~ taxonomy.%s', $machineName)] : [];
    }

    public function importDocument(string $name, array $incoming): array
    {
        $machineName = $this->machineNameOf($name);
        if (preg_match(Vocabulary::MACHINE_NAME_PATTERN, $machineName) !== 1) {
            return [];
        }

        $vocabulary = $this->repository->findOneByMachineName($machineName);
        $label = (string) ($incoming['label'] ?? $machineName);

        if ($vocabulary === null) {
            $vocabulary = new Vocabulary($machineName, $label);
            $this->entityManager->persist($vocabulary);
            $applied = [sprintf('created taxonomy.%s', $machineName)];
        } else {
            $vocabulary->setLabel($label);
            $applied = [sprintf('updated taxonomy.%s', $machineName)];
        }

        $vocabulary
            ->setDescription(\is_string($incoming['description'] ?? null) ? $incoming['description'] : null)
            ->setHierarchical((bool) ($incoming['hierarchical'] ?? true))
            ->setWeight((int) ($incoming['weight'] ?? 0));

        return $applied;
    }

    public function afterImport(): void
    {
        $this->registry->invalidate();
    }

    private function machineNameOf(string $document): string
    {
        $machineName = substr($document, \strlen(self::PREFIX));

        return preg_match(Vocabulary::MACHINE_NAME_PATTERN, $machineName) === 1 ? $machineName : '';
    }

    /**
     * @param array<string, mixed> $incoming
     *
     * @return array<string, mixed>
     */
    private function normalizeIncoming(array $incoming): array
    {
        $normalized = [
            'label' => (string) ($incoming['label'] ?? ''),
            'description' => \is_string($incoming['description'] ?? null) && $incoming['description'] !== '' ? $incoming['description'] : null,
            'hierarchical' => (bool) ($incoming['hierarchical'] ?? true),
            'weight' => (int) ($incoming['weight'] ?? 0),
        ];
        ksort($normalized);

        return $normalized;
    }
}
