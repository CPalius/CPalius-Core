<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Compositional attribute: entity tracks publish status and publishedAt (implementation in PublishableTrait).
 * Usable without #[CpResource]; ResourceRegistrationPass records it in ResourceDefinition metadata.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Publishable
{
    /**
     * @param string $defaultStatus Initial status when the entity is created (e.g. "draft").
     */
    public function __construct(
        public readonly string $defaultStatus = 'draft',
    ) {
    }
}
