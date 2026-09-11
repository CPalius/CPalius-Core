<?php

declare(strict_types=1);

namespace App\Core\Resource;

/**
 * Immutable compile-time snapshot of #[CpResource] and behavior flags on an entity.
 * $auditable ORs CpResource and #[Auditable]; $publishable/$softDeletable come from behavior attributes only.
 */
final class ResourceDefinition
{
    /**
     * @param class-string $entityClass
     * @param list<string> $capabilities Short action names (e.g. create).
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly string $name,
        public readonly string $module,
        public readonly array $capabilities,
        public readonly bool $auditable,
        public readonly bool $multiTenant,
        public readonly ?string $workflow,
        public readonly bool $publishable = false,
        public readonly bool $softDeletable = false,
    ) {
    }

    /**
     * Expands short capabilities to full names like vehicle.create.
     *
     * @return list<string>
     */
    public function getExpandedCapabilities(): array
    {
        return array_values(array_map(
            fn (string $capability): string => sprintf('%s.%s', $this->name, $capability),
            $this->capabilities,
        ));
    }
}
