<?php

declare(strict_types=1);

namespace App\Core\Resource;

/**
 * Single source of truth for all #[CpResource] entities; passive store filled by ResourceRegistrationPass.
 * No scanning at runtime — consumed by CapabilityRegistry, QueryScopeApplier, TenantFilter, etc.
 */
final class ResourceRegistry
{
    /** @var array<string, ResourceDefinition> entity FQCN => definition */
    private array $byClass = [];

    /** @var array<string, string> resource name => entity FQCN */
    private array $classByName = [];

    public function add(ResourceDefinition $definition): void
    {
        $this->byClass[$definition->entityClass] = $definition;

        // Empty name: behavior-only entities (#[Publishable] etc.) are not reachable via getByName().
        if ($definition->name !== '') {
            $this->classByName[$definition->name] = $definition->entityClass;
        }
    }

    /**
     * @param class-string $entityClass
     */
    public function has(string $entityClass): bool
    {
        return isset($this->byClass[$entityClass]);
    }

    /**
     * @param class-string $entityClass
     */
    public function get(string $entityClass): ?ResourceDefinition
    {
        return $this->byClass[$entityClass] ?? null;
    }

    public function getByName(string $name): ?ResourceDefinition
    {
        $class = $this->classByName[$name] ?? null;

        return $class !== null ? $this->byClass[$class] : null;
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function all(): array
    {
        return array_values($this->byClass);
    }

    /**
     * Entity FQCNs with multiTenant: true (for TenantFilter).
     *
     * @return list<class-string>
     */
    public function getMultiTenantEntityClasses(): array
    {
        return array_values(array_map(
            static fn (ResourceDefinition $d): string => $d->entityClass,
            array_filter($this->byClass, static fn (ResourceDefinition $d): bool => $d->multiTenant),
        ));
    }

    /**
     * Entity FQCNs with publishable: true (#[Publishable] + PublishableTrait).
     *
     * @return list<class-string>
     */
    public function getPublishableEntityClasses(): array
    {
        return $this->filterEntityClasses(static fn (ResourceDefinition $d): bool => $d->publishable);
    }

    /**
     * Entity FQCNs with softDeletable: true (#[SoftDeletable] + SoftDeletableTrait).
     *
     * @return list<class-string>
     */
    public function getSoftDeletableEntityClasses(): array
    {
        return $this->filterEntityClasses(static fn (ResourceDefinition $d): bool => $d->softDeletable);
    }

    /**
     * Entity FQCNs with auditable: true (for audit log listeners).
     *
     * @return list<class-string>
     */
    public function getAuditableEntityClasses(): array
    {
        return $this->filterEntityClasses(static fn (ResourceDefinition $d): bool => $d->auditable);
    }

    /**
     * @param callable(ResourceDefinition): bool $predicate
     * @return list<class-string>
     */
    private function filterEntityClasses(callable $predicate): array
    {
        return array_values(array_map(
            static fn (ResourceDefinition $d): string => $d->entityClass,
            array_filter($this->byClass, $predicate),
        ));
    }
}
