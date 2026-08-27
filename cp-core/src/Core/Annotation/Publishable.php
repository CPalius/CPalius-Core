<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Bir entity'nin yayın durumu (taslak/yayında/zamanlı) ve yayınlanma
 * tarihi izleyeceğini bildiren kompozisyonel davranış (behavior) attribute'u.
 *
 * #[CpResource] gibi dev bir monolit DEĞİLDİR: bir entity #[CpResource]
 * taşımadan da sadece #[Publishable] taşıyabilir (ör. platform-genelinde
 * bir yetenek üretmeyen, ama yine de yayın durumu yönetimine ihtiyaç duyan
 * bir entity). Alan/metot uygulaması PublishableTrait'te yaşar; bu
 * attribute sadece "bu sınıf bu davranışı destekliyor" bilgisini taşır —
 * ResourceRegistrationPass bunu derleme zamanında okuyup ResourceDefinition
 * metadata'sına ekler (bkz. ResourceDefinition::$behaviors).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Publishable
{
    /**
     * @param string $defaultStatus Entity ilk oluşturulduğunda alacağı
     *   varsayılan durum (ör. "draft").
     */
    public function __construct(
        public readonly string $defaultStatus = 'draft',
    ) {
    }
}
