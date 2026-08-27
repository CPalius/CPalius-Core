<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Bir entity üzerindeki her değişikliğin (eski değer, yeni değer,
 * değiştiren kullanıcı) otomatik olarak audit log altyapısına
 * kaydedileceğini bildiren kompozisyonel davranış attribute'u.
 *
 * #[CpResource]'un kendi $auditable bool bayrağıyla ÇAKIŞMAZ: bir kaynak
 * hem #[CpResource(auditable: true)] hem #[Auditable] taşıyabilir (ikisi
 * de aynı sonuca ulaşır), ama #[Auditable] platform-genelinde bir kaynak
 * (capability üretmeyen, sade bir entity) için de bağımsız kullanılabilir.
 * ResourceRegistrationPass her iki kaynağı da okuyup birleştirir (bkz.
 * ResourceDefinition::$auditable).
 *
 * $trackedFields boş bırakılırsa (varsayılan) entity'nin TÜM alanları
 * izlenir; belirli alanlarla sınırlamak isteyen bir kaynak bu listeyi
 * doldurur (ör. sadece ["status", "price"] gibi hassas/kritik alanlar).
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
