<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotent field creation for module installers and install profiles.
 *
 * "ensure" only ever CREATES a missing field — it never overwrites one an editor
 * may have tuned from /aacp/fields. Removal is a deliberate, separate operation.
 */
final class FieldDefinitionSeeder
{
    public function __construct(
        private readonly FieldDefinitionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FieldTypeRegistry $types,
        private readonly FieldDefinitionRegistry $registry,
    ) {
    }

    /**
     * @param list<array{
     *     bundle: string, name: string, type: string, label?: string, help?: ?string,
     *     required?: bool, cardinality?: int, translatable?: bool, queryable?: bool,
     *     group?: ?string, weight?: int, settings?: array<string, mixed>,
     *     view_capability?: ?string, edit_capability?: ?string
     * }> $specs
     *
     * @return int number of fields created
     */
    public function ensure(array $specs): int
    {
        $created = 0;
        $bundles = [];

        foreach ($specs as $spec) {
            $bundle = trim((string) ($spec['bundle'] ?? ''));
            $name = trim((string) ($spec['name'] ?? ''));
            $type = (string) ($spec['type'] ?? '');

            if ($bundle === '' || preg_match(FieldDefinition::NAME_PATTERN, $name) !== 1 || !$this->types->has($type)) {
                continue;
            }
            if ($this->repository->findOneByBundleAndName($bundle, $name) !== null) {
                continue;
            }

            $definition = new FieldDefinition($bundle, $name, $type, (string) ($spec['label'] ?? ucfirst(str_replace('_', ' ', $name))));
            $definition
                ->setHelp($spec['help'] ?? null)
                ->setRequired((bool) ($spec['required'] ?? false))
                ->setCardinality((int) ($spec['cardinality'] ?? 1))
                ->setTranslatable((bool) ($spec['translatable'] ?? true))
                ->setQueryable((bool) ($spec['queryable'] ?? false))
                ->setFieldGroup($spec['group'] ?? null)
                ->setWeight((int) ($spec['weight'] ?? 0))
                ->setSettings($this->types->get($type)->normalizeSettings($spec['settings'] ?? []))
                ->setViewCapability($spec['view_capability'] ?? null)
                ->setEditCapability($spec['edit_capability'] ?? null);

            $this->entityManager->persist($definition);
            $bundles[$bundle] = true;
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
            foreach (array_keys($bundles) as $bundle) {
                $this->registry->invalidate($bundle);
            }
        }

        return $created;
    }
}
