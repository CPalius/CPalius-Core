<?php

declare(strict_types=1);

namespace App\Core\Config\Provider;

use App\Core\Config\ConfigProviderInterface;
use App\Core\Display\Entity\EntityDisplay;
use App\Core\Display\EntityDisplayRegistry;
use App\Core\Display\Repository\EntityDisplayRepository;
use App\Core\Display\ViewModeRegistry;
use App\Core\Field\Entity\FieldDefinition;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document per bundle: display.{bundle}, nested by view mode. Authoritative
 * on import — an override absent from disk is removed (this table only ever
 * holds overrides; removing one reverts a field to its own default display,
 * never touches content, so unlike TaxonomyConfigProvider this is safe to
 * make authoritative like FieldConfigProvider).
 */
final class EntityDisplayConfigProvider implements ConfigProviderInterface
{
    private const PREFIX = 'display.';

    public function __construct(
        private readonly EntityDisplayRepository $repository,
        private readonly ViewModeRegistry $viewModes,
        private readonly EntityDisplayRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function documents(): array
    {
        return array_map(
            static fn (string $bundle): string => self::PREFIX.$bundle,
            $this->repository->distinctBundles(),
        );
    }

    public function ownsDocument(string $name): bool
    {
        return str_starts_with($name, self::PREFIX) && $this->bundleOf($name) !== '';
    }

    public function exportDocument(string $name): array
    {
        $out = [];
        foreach ($this->repository->findByBundle($this->bundleOf($name)) as $row) {
            $out[$row->getViewMode()][$row->getFieldName()] = $row->toArray();
        }
        ksort($out);

        return $out;
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $bundle = $this->bundleOf($name);
        $live = $this->indexed($bundle);
        $changes = [];

        foreach ($live as $viewMode => $fields) {
            foreach ($fields as $fieldName => $liveRow) {
                $incomingRow = $incoming[$viewMode][$fieldName] ?? null;
                if ($incomingRow === null) {
                    $changes[] = sprintf('- %s.%s.%s', $bundle, $viewMode, $fieldName);
                } elseif ($this->normalize($liveRow) !== $this->normalize($incomingRow)) {
                    $changes[] = sprintf('~ %s.%s.%s', $bundle, $viewMode, $fieldName);
                }
            }
        }

        foreach ($incoming as $viewMode => $fields) {
            if (!\is_string($viewMode) || !\is_array($fields)) {
                continue;
            }
            foreach (array_keys($fields) as $fieldName) {
                if (!isset($live[$viewMode][$fieldName])) {
                    $changes[] = sprintf('+ %s.%s.%s', $bundle, $viewMode, $fieldName);
                }
            }
        }

        return $changes;
    }

    public function importDocument(string $name, array $incoming): array
    {
        $bundle = $this->bundleOf($name);
        $live = $this->indexed($bundle);
        $applied = [];
        $seen = [];

        foreach ($incoming as $viewMode => $fields) {
            if (!\is_string($viewMode) || !$this->viewModes->has($viewMode) || !\is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldName => $spec) {
                if (!\is_string($fieldName) || preg_match(FieldDefinition::NAME_PATTERN, $fieldName) !== 1 || !\is_array($spec)) {
                    continue;
                }

                $seen[$viewMode][$fieldName] = true;
                $row = $live[$viewMode][$fieldName] ?? null;

                if ($row === null) {
                    $row = new EntityDisplay($bundle, $viewMode, $fieldName);
                    $this->entityManager->persist($row);
                    $applied[] = sprintf('created %s.%s.%s', $bundle, $viewMode, $fieldName);
                } else {
                    $applied[] = sprintf('updated %s.%s.%s', $bundle, $viewMode, $fieldName);
                }

                $row->setVisible((bool) ($spec['visible'] ?? true))
                    ->setWeight((int) ($spec['weight'] ?? 0))
                    ->setLabelDisplay((string) ($spec['label_display'] ?? EntityDisplay::LABEL_ABOVE));
            }
        }

        foreach ($live as $viewMode => $fields) {
            foreach ($fields as $fieldName => $row) {
                if (!isset($seen[$viewMode][$fieldName])) {
                    $this->entityManager->remove($row);
                    $applied[] = sprintf('removed %s.%s.%s', $bundle, $viewMode, $fieldName);
                }
            }
        }

        return $applied;
    }

    public function afterImport(): void
    {
        $this->registry->invalidate();
    }

    /**
     * @return array<string, array<string, EntityDisplay>> viewMode => fieldName => row
     */
    private function indexed(string $bundle): array
    {
        $out = [];
        foreach ($this->repository->findByBundle($bundle) as $row) {
            $out[$row->getViewMode()][$row->getFieldName()] = $row;
        }

        return $out;
    }

    /**
     * @param EntityDisplay|array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalize(EntityDisplay|array $row): array
    {
        $data = $row instanceof EntityDisplay ? $row->toArray() : $row;
        $normalized = [
            'visible' => (bool) ($data['visible'] ?? true),
            'weight' => (int) ($data['weight'] ?? 0),
            'label_display' => (string) ($data['label_display'] ?? EntityDisplay::LABEL_ABOVE),
        ];
        ksort($normalized);

        return $normalized;
    }

    private function bundleOf(string $document): string
    {
        $bundle = substr($document, \strlen(self::PREFIX));

        return preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $bundle) === 1 ? $bundle : '';
    }
}
