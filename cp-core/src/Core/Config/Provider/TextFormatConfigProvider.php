<?php

declare(strict_types=1);

namespace App\Core\Config\Provider;

use App\Core\Config\ConfigProviderInterface;
use App\Core\TextFormat\Entity\TextFormat;
use App\Core\TextFormat\Repository\TextFormatRepository;
use App\Core\TextFormat\TextFormatRegistry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document per format: text_format.{id}. Authoritative for the override
 * row — deleting the file removes the DB row and the catalog YAML becomes
 * live again. Stored rich_text values keep their format id; they never lose
 * content because a missing override falls back to YAML.
 */
final class TextFormatConfigProvider implements ConfigProviderInterface
{
    private const PREFIX = 'text_format.';

    public function __construct(
        private readonly TextFormatRepository $repository,
        private readonly TextFormatRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function documents(): array
    {
        return array_map(
            static fn (string $id): string => self::PREFIX.$id,
            $this->registry->ids(),
        );
    }

    public function ownsDocument(string $name): bool
    {
        return str_starts_with($name, self::PREFIX) && $this->idOf($name) !== '';
    }

    public function exportDocument(string $name): array
    {
        $format = $this->registry->get($this->idOf($name));
        if ($format === null) {
            return [];
        }

        return [
            'label' => $format->label,
            'description' => $format->description,
            'wysiwyg' => $format->wysiwyg,
            'filters' => $format->filters,
        ];
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $id = $this->idOf($name);
        $live = $this->exportDocument($name);
        if ($live === [] && $incoming !== []) {
            return [sprintf('+ %s', $id)];
        }
        if ($this->normalize($live) === $this->normalize($incoming)) {
            return [];
        }

        return [sprintf('~ %s', $id)];
    }

    public function importDocument(string $name, array $incoming): array
    {
        $id = $this->idOf($name);
        if ($id === '') {
            return [];
        }

        $filters = $this->registry->normalizeFilters($incoming['filters'] ?? []);
        $row = $this->repository->findOneByMachineName($id);

        if ($filters === [] && $incoming === []) {
            if ($row !== null) {
                $this->entityManager->remove($row);

                return [sprintf('removed %s', $id)];
            }

            return [];
        }

        if ($row === null) {
            $row = new TextFormat($id, (string) ($incoming['label'] ?? $id));
            $this->entityManager->persist($row);
            $applied = [sprintf('created %s', $id)];
        } else {
            $applied = [sprintf('updated %s', $id)];
        }

        $row->setLabel((string) ($incoming['label'] ?? $row->getLabel()))
            ->setDescription((string) ($incoming['description'] ?? ''))
            ->setWysiwyg((bool) ($incoming['wysiwyg'] ?? false))
            ->setFilters($filters);

        return $applied;
    }

    public function afterImport(): void
    {
        $this->registry->invalidate();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $normalized = [
            'label' => (string) ($data['label'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'wysiwyg' => (bool) ($data['wysiwyg'] ?? false),
            'filters' => $this->registry->normalizeFilters($data['filters'] ?? []),
        ];
        ksort($normalized);

        return $normalized;
    }

    private function idOf(string $document): string
    {
        $id = substr($document, \strlen(self::PREFIX));

        return preg_match(TextFormatRegistry::ID_PATTERN, $id) === 1 ? $id : '';
    }
}
