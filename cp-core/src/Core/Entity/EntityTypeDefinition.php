<?php

declare(strict_types=1);

namespace App\Core\Entity;

/**
 * Immutable, container-friendly view of one #[CpEntityType] declaration.
 * Built by EntityTypeRegistrationPass from a plain scalar array (compiler passes
 * cannot inject objects), reconstructed here.
 */
final class EntityTypeDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $className,
        public readonly string $label,
        public readonly bool $fieldable,
        public readonly bool $bundleable,
        public readonly bool $revisionable,
        public readonly bool $translatable,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            className: (string) ($data['className'] ?? ''),
            label: (string) ($data['label'] ?? ($data['id'] ?? '')),
            fieldable: (bool) ($data['fieldable'] ?? true),
            bundleable: (bool) ($data['bundleable'] ?? false),
            revisionable: (bool) ($data['revisionable'] ?? false),
            translatable: (bool) ($data['translatable'] ?? false),
        );
    }

    /**
     * The single bundle id for a non-bundleable entity type (equals $id).
     * Bundleable types (Node) have many, resolved per-object via fieldableBundle().
     */
    public function defaultBundle(): string
    {
        return $this->id;
    }
}
