<?php

declare(strict_types=1);

namespace App\Core\Resource;

/**
 * #[CpResource] ile işaretlenmiş TÜM entity'lerin tek doğruluk kaynağı.
 *
 * Bu registry'nin kendisi hiçbir tarama yapmaz (Manifesto Law 2.1 ruhuyla
 * aynı ayrım: runtime servisi ile keşif/derleme mantığı ayrıdır) — sadece
 * ResourceRegistrationPass tarafından derleme zamanında doldurulan pasif
 * bir depodur. CapabilityRegistry, QueryScopeApplier ve TenantFilter gibi
 * tüketiciler buradan okur.
 */
final class ResourceRegistry
{
    /** @var array<string, ResourceDefinition> entity FQCN => tanım */
    private array $byClass = [];

    /** @var array<string, string> kaynak adı => entity FQCN */
    private array $classByName = [];

    public function add(ResourceDefinition $definition): void
    {
        $this->byClass[$definition->entityClass] = $definition;

        // İsim boş olabilir: #[CpResource] taşımadan sadece bir davranış
        // attribute'u (#[Publishable] vb.) ile kaydedilen entity'lerin
        // platform kaynağı adı yoktur — bunlar getByName() ile ARANAMAZ,
        // sadece getByClass()/all() üzerinden erişilebilir.
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
     * multiTenant: true olan kaynakların entity FQCN listesi —
     * TenantFilter'ın hangi sınıflara kısıt enjekte edeceğini bilmesi için.
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
     * publishable: true olan kaynakların entity FQCN listesi (bkz.
     * #[Publishable] + PublishableTrait).
     *
     * @return list<class-string>
     */
    public function getPublishableEntityClasses(): array
    {
        return $this->filterEntityClasses(static fn (ResourceDefinition $d): bool => $d->publishable);
    }

    /**
     * softDeletable: true olan kaynakların entity FQCN listesi (bkz.
     * #[SoftDeletable] + SoftDeletableTrait) — ör. bir "silinmişleri
     * gizle" Doctrine filter'ının hangi sınıflara uygulanacağını bilmesi
     * için.
     *
     * @return list<class-string>
     */
    public function getSoftDeletableEntityClasses(): array
    {
        return $this->filterEntityClasses(static fn (ResourceDefinition $d): bool => $d->softDeletable);
    }

    /**
     * auditable: true olan kaynakların entity FQCN listesi — bir audit
     * log event listener'ının hangi entity'leri izleyeceğini bilmesi için.
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
