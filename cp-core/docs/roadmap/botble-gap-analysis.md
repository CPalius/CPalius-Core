# Botble karşılaştırması ve yol haritası

Botble (Laravel CMS) yalnızca **fikir kaynağı** olarak incelendi; Regular lisans nedeniyle kod alınmaz, mantık Symfony tarafında kendi tasarımımızla kurulur. İnceleme ana sınıflar ve veritabanı şemaları düzeyindedir, her dosya satır satır okunmadı.

## Bizde olmayanlar (kodda arandı)
Herkese açık iletişim formu, çerez onayı, bülten, sosyal giriş, shortcode, galeri, medya klasörleri, listelerde toplu işlem ve CSV dışa aktarma.

## Bizde daha ileri veya eşit
Güvenlik (2FA, flood, IP engeli, yetenek sistemi), webhook, API (idempotency), yedek ve uzak hedef, SEO (IndexNow, llms.txt, schema), önbellek/Varnish, iş akışı, revizyon, captcha sağlayıcıları, AI çeviri, test kapsamı.

## Sıra

| Sıra | İş | Boyut | Not |
|---|---|---|---|
| 1 | Çerez onayı (yapıldı) | küçük | Onay durumu tarayıcıda çözülür; sunucu çıktısı herkes için aynı kalır (origin cache). Consent Mode, kategoriler, CSP nonce, metinler dil başına. |
| 2 | 404 izleme + tek tıkla yönlendirme (yapıldı) | küçük | `kernel.exception`, URL özetine göre tek satır, sayaç, yönlendiren; cron ile temizlik. |
| 3 | Menü öğesi alanları (yapıldı) | küçük | `rel`, `icon`, `cssClass` eklendi. Ayrı konum kaydı yapılmadı: menü tanımlayıcısı (`cp_menu("header")`) zaten konum işini görüyor. |
| 4 | Dil: bayrak, RTL, "menüyü kopyala", çeviriyi önceden doldur | küçük-orta | "Varsayılan dili URL'den gizle" ayrı ve riskli (kanonik, hreflang). |
| 5 | Shortcode + kayıtlı bloklar | orta | `WidgetTypeInterface` yeniden kullanılır: kenar çubuğunda widget, içerikte shortcode, ana sayfada Portal bloğu. Yalnızca güvenilir metin biçimlerinde çalışır. |
| 6 | İletişim formu | orta | `Core/Field` türleri, `CaptchaService`, `FloodService`, posta şablonları, bildirim zili. |
| 7 | Medya: klasör, alt metin, çöp, "nerede kullanılıyor" (yapıldı) | orta | Klasörler `cp_media_folders`, dosya→klasör `cp_media_placements` (core `cp_assets` dokunulmadı); sürükle-bırak yükleme/taşıma, favori/son açılan, kopya (içerik-adresli depoda aynı dosya, ayrı satır), alt metin, zip indirme, kullanım izleme (görsel/dosya alanları + avatar). Silme genel çöp kutusuna gider; dosya satır çöpten çıkınca silinir. Kırpma, URL'den yükleme, klasör yetkisi yapılmadı. |
| 7b | Genel çöp kutusu (yapıldı) | büyük | `Core/Trash`: ORM ile silinen her satır ham haliyle `cp_trash_items`'a yazılır (onFlush), aynı flush = aynı `batch_id`; tür başına aç/kapa + saklama günü (`cp_trash_rules`, `*` = varsayılan); geri yükleme düz INSERT; cron `trash.purge`. Yakalamaz: toplu DQL/SQL DELETE, DB `ON DELETE CASCADE`, çoktan-çoğa bağ satırları. `#[NotTrashable]` ile hariç tutulur. |
| 8 | DataSync (CSV içe/dışa aktarma) | orta | Sütun tanımı, parçalı doğrulama, Messenger ile içe aktarma. |
| 9 | Tema ayarları şeması (dil başına) | orta | Alan şemasından form; özel kod için CSP nonce. |
| 10 | Liste çekirdeği (toplu işlem, sütun görünürlüğü, CSV) | büyük | Modül başına tekrar eden liste kodunu azaltır. |
| 11 | Sosyal giriş | orta | Yeni bağımlılık yok, HttpClient; hesap bağlama yalnızca doğrulanmış e-postayla. |
| 12 | Görsel sayfa kurucu | büyük | Madde 5'ten sonra. |

## Botble'dan kaçınılacaklar
- Resmî olmayan Google ucuyla otomatik çeviri (bizim AI çevirimiz daha sağlam).
- Widget'ları dil başına tüm seti kopyalayarak çok dilli yapmak (bizde alan başına çok dilli).
- Shortcode önbelleğini ad listeleriyle dışarıda bırakmak (türün kendisi "kullanıcıya özel" bayrağını bildirmeli).

## Doğrulanacaklar
İçindekiler/okuma süresi var mı, 404 listesi ekranı, görsel piksel sınırı, "kopya olarak çeviri" düğmesi.
