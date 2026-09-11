<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Entity\FieldableInterface;
use App\Core\Field\Entity\FieldDefinition;

/**
 * The single choke point for turning submitted field input into stored values
 * (Manifesto Law 5.3). Only keys backed by a FieldDefinition are ever produced —
 * raw request arrays are never merged as-is.
 */
final class FieldValueNormalizer
{
    public function __construct(
        private readonly FieldDefinitionRegistry $definitions,
        private readonly FieldTypeRegistry $types,
    ) {
    }

    /**
     * @param array<string, mixed> $rawValues fieldName => raw submitted value(s)
     *
     * @return array<string, mixed> fieldName => clean stored value(s); absent when empty
     */
    public function normalize(string $bundle, array $rawValues, ?FieldableInterface $entity, string $locale): array
    {
        $out = [];

        foreach ($this->definitions->getFieldsForBundle($bundle) as $definition) {
            if (!$this->types->has($definition->getType())) {
                // A removed/broken type: never write an unvalidated value for it.
                continue;
            }

            $name = $definition->getName();
            $hasInput = \array_key_exists($name, $rawValues);
            $raw = $hasInput ? $rawValues[$name] : null;

            $type = $this->types->get($definition->getType());
            $context = new FieldContext($definition, $locale, $entity);

            $value = $definition->isMultiValue()
                ? $this->normalizeMulti($type, $raw, $definition, $context)
                : $type->normalize($definition->getType() === 'rich_text' ? $raw : $this->scalarish($raw), $context);

            if ($this->isEmpty($value)) {
                if (!$hasInput) {
                    $default = $definition->isMultiValue() ? [] : $type->defaultValue($context);
                    if (!$this->isEmpty($default)) {
                        $out[$name] = $default;
                    }
                }

                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function normalizeMulti(FieldTypeInterface $type, mixed $raw, FieldDefinition $definition, FieldContext $context): array
    {
        if (\is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $limit = $definition->getCardinality() === FieldDefinition::UNLIMITED ? 1000 : $definition->getCardinality();
        $values = [];

        foreach ($raw as $item) {
            $normalized = $type->normalize(
                $definition->getType() === 'rich_text' ? $item : $this->scalarish($item),
                $context,
            );
            if (!$this->isEmpty($normalized)) {
                $values[] = $normalized;
            }
            if (\count($values) >= $limit) {
                break;
            }
        }

        return array_values($values);
    }

    private function scalarish(mixed $raw): mixed
    {
        return \is_array($raw) ? null : $raw;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (\is_array($value) && \array_key_exists('value', $value)) {
            return $value['value'] === null || $value['value'] === '';
        }

        return false;
    }
}
