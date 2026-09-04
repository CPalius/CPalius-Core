<?php

declare(strict_types=1);

namespace App\Core\Media\Exception;

/**
 * Dosya çekirdeğe hiç ulaşmadan bozulmuşsa fırlatılır: PHP yükleme hatası
 * (boyut aşımı, kısmi yükleme, geçici dizin yok), okunamayan bir geçici
 * dosya, veya finfo'nun içeriği hiç tespit edememesi.
 *
 * UnsupportedAssetTypeException'dan AYRI tutulur çünkü ikisi kullanıcıya
 * farklı şeyler söyler: "bu türü kabul etmiyoruz" ile "dosya bize sağlam
 * ulaşmadı" farklı sorunlardır ve farklı çözümleri vardır (biri dosya
 * değiştirmeyi, diğeri tekrar denemeyi veya php.ini'ye bakmayı gerektirir).
 *
 * FAIL-CLOSED: finfo bir MIME tipi üretemediğinde "muhtemelen zararsızdır"
 * varsayımıyla devam ETMEK YERİNE bu istisna fırlatılır. Tanınmayan
 * içerik, tanımı gereği güvenli olduğu kanıtlanmamış içeriktir.
 */
final class InvalidUploadException extends AssetUploadException
{
}
