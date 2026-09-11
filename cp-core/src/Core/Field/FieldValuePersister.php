<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Entity\FieldableInterface;

/**
 * Writes submitted field input onto a fieldable entity. normalize (Law 5.3) →
 * validate → merge into the entity's field-value bag, leaving non-field keys
 * untouched. The caller flushes.
 */
final class FieldValuePersister
{
    public function __construct(
        private readonly FieldDefinitionRegistry $definitions,
        private readonly FieldTypeRegistry $types,
        private readonly FieldValueNormalizer $normalizer,
        private readonly FieldValidator $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted fieldName => raw submitted value(s)
     *
     * @return array<string, list<string>> validation errors ([] = persisted)
     */
    public function persist(FieldableInterface $entity, array $submitted, ?string $locale = null): array
    {
        $bundle = $entity->fieldableBundle();
        $locale ??= $entity->fieldableLocale();

        $normalized = $this->normalizer->normalize($bundle, $submitted, $entity, $locale);
        $errors = $this->validator->validate($bundle, $normalized, $entity, $locale);
        if ($errors !== []) {
            return $errors;
        }

        $data = $entity->getFieldableData();

        foreach ($this->definitions->getFieldsForBundle($bundle) as $definition) {
            if (!$this->types->has($definition->getType())) {
                continue;
            }

            $name = $definition->getName();
            if (\array_key_exists($name, $normalized)) {
                $data[$name] = $normalized[$name];
            } elseif (\array_key_exists($name, $submitted)) {
                // The field was present in the form but came back empty → clear it.
                unset($data[$name]);
            }
        }

        $entity->setFieldableData($data);

        return [];
    }
}
