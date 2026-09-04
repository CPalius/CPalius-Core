<?php

declare(strict_types=1);

namespace App\Core\Media\Exception;

/**
 * Yüklenen dosyanın GERÇEK içeriği (finfo ile tespit edilen MIME tipi)
 * MimeTypeAllowlist'te yoksa fırlatılır.
 *
 * Tespit edilen MIME tipi istisnanın üzerinde ayrı bir alan olarak
 * taşınır: çağıran taraf bunu çeviri parametresi olarak kullanır
 * (bkz. "media.admin.error.unsupported_type") ve mesajı kendi diline
 * çevirir. İstisna mesajının KENDİSİ bilinçli olarak İngilizce/teknik
 * kalır — istisna mesajları loga gider, kullanıcıya değil; kullanıcıya
 * giden metin her zaman çeviri katmanından geçer.
 *
 * GÜVENLİK NOTU: $detectedMimeType finfo'dan gelir, yani DOSYA
 * İÇERİĞİNDEN türetilmiş sınırlı bir değerdir — istemcinin gönderdiği
 * Content-Type başlığı veya dosya adı DEĞİLDİR. Yine de kullanıcıya
 * gösterilirken Twig'in otomatik kaçışına güvenilir; hiçbir yerde "|raw"
 * ile basılmamalıdır.
 */
final class UnsupportedAssetTypeException extends AssetUploadException
{
    public function __construct(
        public readonly string $detectedMimeType,
    ) {
        parent::__construct(sprintf(
            'Upload rejected: detected MIME type "%s" is not in the core allowlist.',
            $detectedMimeType,
        ));
    }
}
