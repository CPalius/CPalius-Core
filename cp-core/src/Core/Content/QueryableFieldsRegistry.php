<?php

namespace App\Core\Content;

use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Module\ModuleContributionCatalog;

/**
 * Node::data (JSON) fields that are flattened into NodeFieldIndex.
 * Two sources, merged: modules declare fields in contributions.yaml
 * (queryable_fields), and editor-defined FieldDefinition rows marked queryable.
 */
final class QueryableFieldsRegistry
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_DATETIME = 'datetime';

    public function __construct(
        private readonly ModuleContributionCatalog $contributions,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * @return array<string, self::TYPE_*>
     */
    public function getFieldsForType(string $nodeType): array
    {
        $fields = [];

        foreach ($this->contributions->queryableFieldsForType($nodeType) as $name => $type) {
            if ($this->isSupportedType($type)) {
                $fields[$name] = $type;
            }
        }

        foreach ($this->fieldDefinitions->getFieldsForBundle($nodeType) as $definition) {
            if (!$definition->isQueryable() || $definition->isMultiValue() || !$this->fieldTypes->has($definition->getType())) {
                continue;
            }

            $kind = $this->fieldTypes->get($definition->getType())->indexKind();
            if ($kind !== null && $this->isSupportedType($kind)) {
                $fields[$definition->getName()] = $kind;
            }
        }

        return $fields;
    }

    private function isSupportedType(string $type): bool
    {
        return \in_array($type, [self::TYPE_STRING, self::TYPE_INT, self::TYPE_DECIMAL, self::TYPE_DATETIME], true);
    }
}
