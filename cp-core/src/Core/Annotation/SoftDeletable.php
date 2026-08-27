<?php

declare(strict_types=1);

namespace App\Core\Annotation;

/**
 * Bir entity'nin veritabanından doğrudan DELETE ile silinmek yerine
 * deletedAt alanı ile "Çöp Kutusu" (Recycle Bin) mantığına sahip olacağını
 * bildiren kompozisyonel davranış attribute'u. Alan/metot uygulaması
 * SoftDeletableTrait'te yaşar.
 *
 * $days, çöp kutusundaki bir kaydın kaç gün sonra otomatik/kalıcı
 * silinmeye uygun sayılacağını belirtir (temizlik komutu/cron bu değeri
 * okur); burada sadece bir politika beyanıdır, silme işlemini KENDİSİ
 * tetiklemez.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SoftDeletable
{
    public function __construct(
        public readonly int $retentionDays = 30,
    ) {
    }
}
