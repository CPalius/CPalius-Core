<?php

declare(strict_types=1);

namespace App\Core\Resource\Admin;

/**
 * Resolved presentation info for one column of a resource entity.
 */
final class ResourceFieldDescriptor
{
    public function __construct(
        public readonly string $property,
        public readonly string $label,
        /** scalar Doctrine type, or 'reference' for a ManyToOne. */
        public readonly string $type,
        public readonly bool $nullable,
        public readonly bool $inList,
        public readonly bool $inForm,
        public readonly bool $readonly,
        public readonly bool $sortable,
        public readonly bool $searchable,
        public readonly int $priority,
        public readonly ?string $widget,
        /** @var class-string|null target entity for a reference field */
        public readonly ?string $targetClass = null,
        /** @var array<string, string>|null value=>label choices for an enum/choice column */
        public readonly ?array $choices = null,
    ) {
    }

    public function isReference(): bool
    {
        return $this->type === 'reference';
    }
}
