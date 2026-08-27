<?php

declare(strict_types=1);

namespace App\Core\Database;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Manifesto Law 5.1'in yazma tarafı: TenantFilter sadece SELECT'leri
 * kısıtlar (Doctrine SQLFilter'lar INSERT/UPDATE'e uygulanmaz). Yeni bir
 * multi-tenant entity persist edildiğinde tenant_id'nin unutulmaması için
 * bu listener, TenantAwareInterface uygulayan her yeni entity'ye aktif
 * tenant'ı otomatik damgalar.
 *
 * Zaten bir tenant_id taşıyan (ör. yönetici panelinden bilinçli olarak
 * başka bir tenant'a atanan) entity'lerin üzerine YAZMAZ — sadece henüz
 * hiç set edilmemiş (null) olanı doldurur.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TenantStampListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof TenantAwareInterface) {
            return;
        }

        if ($entity->getTenantId() !== null) {
            return;
        }

        if (!$this->tenantContext->has()) {
            return;
        }

        $entity->setTenantId($this->tenantContext->get());
    }
}
