<?php

declare(strict_types=1);

namespace App\Core\Resource;

/**
 * Bir entity üzerindeki #[CpResource] attribute'unun, container derleme
 * zamanında donmuş (immutable) anlık görüntüsü. ResourceRegistry bu
 * DTO'ları taşır — reflection'ı tekrar tekrar çalıştırmamak için attribute
 * çözümlemesi sadece CompilerPass aşamasında bir kez yapılır.
 *
 * $auditable, hem #[CpResource(auditable: true)] hem de bağımsız
 * #[Auditable] attribute'undan gelebilir (ikisi OR'lanır) — bkz.
 * ResourceRegistrationPass::detectBehaviors(). $publishable ve
 * $softDeletable ise SADECE ilgili kompozisyonel davranış attribute'unun
 * (#[Publishable], #[SoftDeletable]) varlığından gelir; #[CpResource]'un
 * bunlarla ilgili bir eşdeğeri yoktur.
 */
final class ResourceDefinition
{
    /**
     * @param class-string $entityClass
     * @param list<string> $capabilities Kısa eylem adları (ör. "create").
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
     * Bu kaynağın kısa yeteneklerini "<name>.<capability>" biçiminde tam
     * yetenek isimlerine genişletir (ör. "vehicle.create").
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
