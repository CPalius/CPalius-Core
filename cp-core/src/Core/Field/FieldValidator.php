<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Entity\FieldableInterface;
use App\Core\Field\Entity\FieldDefinition;

/**
 * Validates normalized field values against their definitions. Runs AFTER
 * FieldValueNormalizer, on the clean stored form.
 */
final class FieldValidator
{
    public function __construct(
        private readonly FieldDefinitionRegistry $definitions,
        private readonly FieldTypeRegistry $types,
    ) {
    }

    /**
     * @param array<string, mixed> $values normalized fieldName => value(s)
     *
     * @return array<string, list<string>> fieldName => violation translation keys ([] overall = valid)
     */
    public function validate(string $bundle, array $values, ?FieldableInterface $entity, string $locale): array
    {
        $errors = [];

        foreach ($this->definitions->getFieldsForBundle($bundle) as $definition) {
            if (!$this->types->has($definition->getType())) {
                continue;
            }

            $name = $definition->getName();
            $value = $values[$name] ?? null;
            $type = $this->types->get($definition->getType());
            $context = new FieldContext($definition, $locale, $entity);
            $fieldErrors = [];

            $isEmpty = $value === null || $value === '' || $value === [];
            if ($definition->isRequired() && $isEmpty) {
                $errors[$name] = ['field.violation.required'];

                continue;
            }
            if ($isEmpty) {
                continue;
            }

            if ($definition->isMultiValue()) {
                $list = \is_array($value) ? $value : [$value];
                $max = $definition->getCardinality();
                if ($max !== FieldDefinition::UNLIMITED && \count($list) > $max) {
                    $fieldErrors[] = 'field.violation.too_many_values';
                }
                foreach ($list as $item) {
                    array_push($fieldErrors, ...$type->validate($item, $context));
                }
            } else {
                array_push($fieldErrors, ...$type->validate($value, $context));
            }

            if ($fieldErrors !== []) {
                $errors[$name] = array_values(array_unique($fieldErrors));
            }
        }

        return $errors;
    }
}
