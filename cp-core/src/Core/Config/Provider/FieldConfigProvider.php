<?php

declare(strict_types=1);

namespace App\Core\Config\Provider;

use App\Core\Config\ConfigProviderInterface;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document per content bundle: field.{bundle}. Import is authoritative for
 * that bundle — a field on disk is created/updated, a field only in the DB is
 * removed (its stored values in Node::data are left untouched).
 */
final class FieldConfigProvider implements ConfigProviderInterface
{
    private const PREFIX = 'field.';

    public function __construct(
        private readonly FieldDefinitionRepository $repository,
        private readonly FieldTypeRegistry $types,
        private readonly FieldDefinitionRegistry $registry,
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
        foreach ($this->repository->findByBundle($this->bundleOf($name)) as $definition) {
            $row = $definition->toArray();
            unset($row['id'], $row['bundle']);
            unset($row['name']);
            $out[$definition->getName()] = $row;
        }

        return $out;
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $bundle = $this->bundleOf($name);
        $live = [];
        foreach ($this->repository->findByBundle($bundle) as $d) {
            $live[$d->getName()] = $d;
        }

        $changes = [];
        foreach ($incoming as $fieldName => $spec) {
            if (!\is_string($fieldName) || !\is_array($spec)) {
                continue;
            }
            if (!isset($live[$fieldName])) {
                $changes[] = sprintf('+ %s.%s', $bundle, $fieldName);
            } elseif ($this->normalizedSpec($live[$fieldName]) !== $this->normalizeIncoming($spec)) {
                $changes[] = sprintf('~ %s.%s', $bundle, $fieldName);
            }
        }
        foreach ($live as $fieldName => $_) {
            if (!\array_key_exists($fieldName, $incoming)) {
                $changes[] = sprintf('- %s.%s', $bundle, $fieldName);
            }
        }

        return $changes;
    }

    public function importDocument(string $name, array $incoming): array
    {
        $bundle = $this->bundleOf($name);
        $live = [];
        foreach ($this->repository->findByBundle($bundle) as $d) {
            $live[$d->getName()] = $d;
        }

        $applied = [];

        foreach ($incoming as $fieldName => $spec) {
            if (!\is_string($fieldName) || preg_match(FieldDefinition::NAME_PATTERN, $fieldName) !== 1 || !\is_array($spec)) {
                continue;
            }
            $type = (string) ($spec['type'] ?? '');
            if (!$this->types->has($type)) {
                $applied[] = sprintf('skipped %s.%s (unknown type "%s")', $bundle, $fieldName, $type);

                continue;
            }

            $definition = $live[$fieldName] ?? null;
            if ($definition === null) {
                $definition = new FieldDefinition($bundle, $fieldName, $type, (string) ($spec['label'] ?? $fieldName));
                $this->entityManager->persist($definition);
                $applied[] = sprintf('created %s.%s', $bundle, $fieldName);
            } else {
                $applied[] = sprintf('updated %s.%s', $bundle, $fieldName);
            }

            $definition
                ->setType($type)
                ->setLabel((string) ($spec['label'] ?? $fieldName))
                ->setHelp($spec['help'] ?? null)
                ->setRequired((bool) ($spec['required'] ?? false))
                ->setCardinality((int) ($spec['cardinality'] ?? 1))
                ->setTranslatable((bool) ($spec['translatable'] ?? true))
                ->setQueryable((bool) ($spec['queryable'] ?? false))
                ->setFieldGroup($spec['group'] ?? null)
                ->setWeight((int) ($spec['weight'] ?? 0))
                ->setSettings($this->types->get($type)->normalizeSettings(\is_array($spec['settings'] ?? null) ? $spec['settings'] : []))
                ->setViewCapability($spec['view_capability'] ?? null)
                ->setEditCapability($spec['edit_capability'] ?? null);
        }

        foreach ($live as $fieldName => $definition) {
            if (!\array_key_exists($fieldName, $incoming)) {
                $this->entityManager->remove($definition);
                $applied[] = sprintf('removed %s.%s', $bundle, $fieldName);
            }
        }

        return $applied;
    }

    public function afterImport(): void
    {
        $this->registry->invalidate();
    }

    private function bundleOf(string $document): string
    {
        $bundle = substr($document, \strlen(self::PREFIX));

        return preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $bundle) === 1 ? $bundle : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedSpec(FieldDefinition $d): array
    {
        $row = $d->toArray();
        unset($row['id'], $row['bundle'], $row['name']);
        ksort($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private function normalizeIncoming(array $spec): array
    {
        $type = (string) ($spec['type'] ?? 'text');

        $normalized = [
            'type' => $type,
            'label' => (string) ($spec['label'] ?? ''),
            'help' => \is_string($spec['help'] ?? null) && $spec['help'] !== '' ? $spec['help'] : null,
            'required' => (bool) ($spec['required'] ?? false),
            'cardinality' => (int) ($spec['cardinality'] ?? 1),
            'translatable' => (bool) ($spec['translatable'] ?? true),
            'queryable' => (bool) ($spec['queryable'] ?? false),
            'group' => \is_string($spec['group'] ?? null) && $spec['group'] !== '' ? $spec['group'] : null,
            'weight' => (int) ($spec['weight'] ?? 0),
            'settings' => $this->types->has($type)
                ? $this->types->get($type)->normalizeSettings(\is_array($spec['settings'] ?? null) ? $spec['settings'] : [])
                : [],
            'view_capability' => $spec['view_capability'] ?? null,
            'edit_capability' => $spec['edit_capability'] ?? null,
        ];
        ksort($normalized);

        return $normalized;
    }
}
