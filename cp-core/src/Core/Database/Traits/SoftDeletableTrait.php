<?php

declare(strict_types=1);

namespace App\Core\Database\Traits;

use Doctrine\ORM\Mapping as ORM;

/**
 * #[SoftDeletable] davranışının somut uygulaması: gerçek bir SQL DELETE
 * yerine deletedAt alanını damgalayarak "Çöp Kutusu" (Recycle Bin)
 * mantığını sağlar. Bu trait KENDİSİ sorguları filtrelemez (ör. "silinmiş
 * kayıtları normal listelerden gizle") — bu davranış, entity'nin
 * repository'sinde bir Doctrine filter (bkz. TenantFilter ile aynı desen,
 * Manifesto Law 5.1) veya QueryScopeApplier tarafında ele alınmalıdır.
 * Trait sadece alan/erişimci iskeletini sağlar.
 */
trait SoftDeletableTrait
{
    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Kaydı çöp kutusuna taşır (soft delete). Tarih verilmezse şimdiki
     * zaman kullanılır.
     */
    public function softDelete(?\DateTimeImmutable $at = null): static
    {
        $this->deletedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Çöp kutusundan geri yükler.
     */
    public function restore(): static
    {
        $this->deletedAt = null;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
