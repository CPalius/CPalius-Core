<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Bir Doctrine entity'sini CPalius'un metadata-güdümlü platformuna
 * (otomatik yetenek üretimi, audit log, multi-tenant izolasyonu) bağlayan
 * sözleşme. Manifesto Law 4.1: platform desteği isteyen her Content
 * (Node) veya Business Record (Resource) entity'si bu attribute'u taşır.
 *
 * $capabilities burada KISA eylem adları olarak verilir (ör. "create",
 * "edit", "delete") — ResourceRegistry bunları $name ile birleştirip
 * "vehicle.create", "vehicle.edit" gibi tam yetenek isimlerine genişletir
 * (bkz. ResourceRegistry::getExpandedCapabilities()).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CpResource
{
    /**
     * @param string $name Kaynağın kısa, benzersiz kimliği (ör. "vehicle").
     *   Yetenek isimlerinin ön eki olur: "<name>.<capability>".
     * @param string $module Bu kaynağı tanımlayan modülün kimliği (ör.
     *   "oto-galeri"). Çekirdek entity'ler için "core".
     * @param list<string> $capabilities Bu kaynak için üretilecek kısa
     *   eylem adları (ör. ["create", "edit", "delete", "view"]).
     * @param bool $auditable true ise bu kaynağın değişiklikleri audit
     *   log altyapısına (ileride kurulacak) kaydedilir.
     * @param bool $multiTenant true ise TenantFilter bu entity'nin tüm
     *   sorgularına otomatik olarak tenant_id kısıtı enjekte eder.
     * @param string|null $workflow Symfony Workflow bileşeninde tanımlı
     *   bir state machine adı (ör. "vehicle_lifecycle"), yoksa null.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $module = 'core',
        public readonly array $capabilities = [],
        public readonly bool $auditable = false,
        public readonly bool $multiTenant = false,
        public readonly ?string $workflow = null,
    ) {
    }
}
