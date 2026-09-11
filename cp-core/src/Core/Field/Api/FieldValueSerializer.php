<?php

declare(strict_types=1);

namespace App\Core\Field\Api;

use App\Core\Display\EntityDisplayRegistry;
use App\Core\Display\ViewModeRegistry;
use App\Core\Entity\FieldableInterface;
use App\Core\Field\FieldTypeRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Raw field values for the machine API. Returns stored values (not HTML);
 * per-field view_capability is honoured. Undefined data keys are never leaked.
 *
 * $viewMode (T2.2) reuses the SAME visibility config Twig rendering uses via
 * FieldRenderer::renderEntity() — define a "teaser" once, get a matching
 * teaser both in the theme and in the API, instead of two places to keep
 * in sync. "default" with no configured overrides returns every field, same
 * as before view modes existed.
 */
final class FieldValueSerializer
{
    public function __construct(
        private readonly FieldTypeRegistry $types,
        private readonly Security $security,
        private readonly EntityDisplayRegistry $displays,
    ) {
    }

    /**
     * @return array<string, mixed> fieldName => stored value
     */
    public function serialize(FieldableInterface $entity, string $viewMode = ViewModeRegistry::DEFAULT): array
    {
        $out = [];
        $data = $entity->getFieldableData();

        foreach ($this->displays->visibleFields($entity->fieldableBundle(), $viewMode) as $resolved) {
            $definition = $resolved->definition;
            if (!$this->types->has($definition->getType())) {
                continue;
            }

            $capability = $definition->getViewCapability();
            if ($capability !== null && !$this->security->isGranted($capability)) {
                continue;
            }

            $value = $data[$definition->getName()] ?? null;
            if ($value !== null) {
                $out[$definition->getName()] = $value;
            }
        }

        return $out;
    }
}
