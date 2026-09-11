<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Compositional attribute: entity changes are recorded in the audit log infrastructure.
 * Complements #[CpResource(auditable: true)]; empty $trackedFields means all fields (see ResourceDefinition::$auditable).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Auditable
{
    /**
     * @param list<string> $trackedFields
     */
    public function __construct(
        public readonly array $trackedFields = [],
    ) {
    }
}
