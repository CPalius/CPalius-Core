<?php

declare(strict_types=1);

namespace App\Core\Database\Traits;

use Doctrine\ORM\Mapping as ORM;

/**
 * #[Publishable] davranışının somut uygulaması. Node entity'sindeki
 * status/publishedAt alan çiftiyle BİREBİR aynı sözleşmeyi taşır — bu
 * trait aslında Node'un zaten elle yazdığı deseni, başka entity'lerin
 * (ör. bir modülün kendi içerik benzeri kaynağı) kod tekrarı yapmadan
 * yeniden kullanabilmesi için çıkarır.
 *
 * Bilinçli olarak Node::STATUS_* sabitlerini yeniden TANIMLAMAZ: bu
 * trait'i kullanan sınıf, kendi durum sabitlerini (ör. "draft",
 * "published", "archived") kendi bağlamına göre tanımlar — trait sadece
 * alan/erişimci iskeletini sağlar, durum kelime dağarcığını dayatmaz.
 */
trait PublishableTrait
{
    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'draft';

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Durumu "published" yapar ve yayın tarihini damgalar. Tarih
     * verilmezse şimdiki zaman kullanılır — Node::publish() ile aynı
     * davranış.
     */
    public function publish(?\DateTimeImmutable $at = null): static
    {
        $this->status = 'published';
        $this->publishedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
