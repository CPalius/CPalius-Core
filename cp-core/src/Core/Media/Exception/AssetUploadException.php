<?php

declare(strict_types=1);

namespace App\Core\Media\Exception;

/**
 * AssetManager::upload() tarafından fırlatılan tüm yükleme hatalarının
 * ortak atası.
 *
 * Neden \RuntimeException değil de kendi hiyerarşimiz (denetim bulgusu
 * SEC-02): doğrulama çekirdeğe taşındığında, çağıran taraf (Media modülü
 * veya ileride başka bir modül) "bu bir KULLANICI hatası mı, yoksa bir
 * SİSTEM hatası mı?" sorusunu ayırt edebilmek zorundadır. Reddedilen bir
 * dosya türü HTTP 400'dür (istemci yanlış bir şey gönderdi); diske
 * yazamamak HTTP 500'dür (sunucu bozuk). Düz \RuntimeException ile bu iki
 * durum ayırt edilemez ve reddedilen her yükleme 500 olarak loglanır —
 * gerçek arızalar gürültü içinde kaybolur.
 *
 * Bu sınıf hiyerarşisi cp-core'dadır ve Media modülüne bağımlı DEĞİLDİR;
 * modül devre dışı bırakılsa bile AssetManager sözleşmesi eksiksiz kalır
 * (Manifesto Law 2 — Core Never Dies).
 */
class AssetUploadException extends \RuntimeException
{
}
