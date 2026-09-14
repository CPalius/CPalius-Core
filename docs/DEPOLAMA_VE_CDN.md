# CPalius — Depolama, Yedek Hedefi ve CDN

1.1.0 "Liman" ile gelen üç ayarın ne yaptığı, neyi yapmadığı ve hangi sırayla
kurulacağı. Ekran: **AACP → Bakım → Depolama ve CDN** (`/aacp/storage`).

İlgili kaynaklar:

- Hedef sürücüleri: [`cp-core/src/Core/Storage/`](../cp-core/src/Core/Storage)
- Medya kopyalama: [`MediaOffloader`](../cp-core/src/Core/Media/MediaOffloader.php)
- URL üretimi: [`AssetUrlGenerator`](../cp-core/src/Core/Media/AssetUrlGenerator.php)
- Yedek sevki: [`BackupShipper`](../cp-core/src/Core/Backup/BackupShipper.php)

---

## 0. Önce anlaşılması gereken tek şey

**Dosyanın aslı her zaman bu sunucuda kalır.** Uzak hedef bir *kopyadır*, taşıma
değil. Bu bir eksiklik değil, bilinçli bir karar:

- `ImageProcessor` küçük görselleri GD ile üretir ve GD diskteki dosyayı okur.
  Aslı uzakta olsaydı her önbellek ıskası, sayfa render'ının ortasında bir ağ
  isteği olurdu.
- `public/uploads/.htaccess` yüklenen dosyanın çalıştırılmasını engelleyen beş
  katmanlı sertleştirmedir (SEC-01). Bu koruma baytlarla birlikte bucket'a
  gitmez; orada koruma bucket politikasıdır ve sizin sorumluluğunuzdadır.
- Dosya yedeği aksi hâlde sitenin medyasını içermemeye başlar ve bu, ancak geri
  yükleme anında fark edilir.

Sonuç: uzak kopya bir **dağıtım optimizasyonudur**. Uzak hedef çökerse site
görselleri kendi diskinden vermeye devam eder.

---

## 1. Sıra önemli

1. **Önce kimlik bilgilerini girin ve sınayın.** Her sağlayıcı kartında tek
   düğme vardır: *Kaydet ve sına*. Sınama gerçek bir dosya yazar, geri okur ve
   siler.
2. **Sonra yönlendirmeyi seçin.** Medya ve yedek hedefleri, sınamayı geçmemiş
   bir sağlayıcıyı seçseniz bile **kullanmaz**; ekran kırmızı rozet gösterir.
3. **CDN'i en son açın**, ve ancak adresin gerçekten dosya servis ettiğini
   tarayıcıda gördükten sonra.

> **Neden tek düğme:** ayrı bir *Kaydet* olsaydı, ayarı değiştirip sınamayı
> atlayan bir operatör geride "son sınama başarılı" rozetiyle birlikte artık var
> olmayan bir yapılandırmanın kaydını bırakırdı.

---

## 2. Sağlayıcılar

### Amazon S3 ve S3 uyumlu servisler

AWS SDK **kurulmaz**. İstekler SigV4 ile elle imzalanır
([`S3Signer`](../cp-core/src/Core/Storage/Driver/S3Signer.php)); gerekçe, üç
HTTP fiili için ~90 MB servis tanımı indirmenin, paylaşımlı hostinge FTP ile
yüklenen bir pakette her kurulumda hissedilmesidir.

| Alan | Değer |
|---|---|
| Bucket | Bucket adı |
| Bölge | `eu-central-1` gibi. **İmza bu değere bağlıdır**; yanlışsa istek reddedilir |
| Endpoint | Boşsa bölgeden üretilir (`s3.<bölge>.amazonaws.com`). MinIO/Spaces için kendi adresi |
| Path-style | AWS'de **kapalı**, MinIO'da **açık** |

### Cloudflare R2

| Alan | Değer |
|---|---|
| Endpoint | `<hesap-kimliği>.r2.cloudflarestorage.com` |
| Bölge | `auto` (R2'de bölge yoktur) |
| Path-style | Ayar olarak sunulmaz; R2 yalnızca onu konuşur ve kod zorlar |

Çıkış trafiği ücretsiz olduğu için görsel dağıtımına en uygun seçenek budur.

### FTP / FTPS

`ext-ftp` gerekir; yoksa kart bunu peşinen söyler. **Pasif modu açık bırakın** —
aktif modda bağlantıyı sunucu size açar ve bunu neredeyse her güvenlik duvarı
düşürür. Sunucu destekliyorsa **FTPS'i açın**: düz FTP parolayı açık metin
taşır.

> Transferler her zaman `FTP_BINARY` ile yapılır. ASCII modu satır sonlarını
> "düzelterek" her görseli ve her gzip arşivini sessizce bozar.

---

## 3. Medya offload

`storage.media.target` bir sağlayıcıya ayarlandığında:

- **Yükleme anında**: dosya `public/uploads` içine yazılır, asset satırı
  veritabanına işlenir, *sonra* kopya gönderilir. Gönderim başarısız olursa
  yükleme yine başarılıdır — `MediaOffloader::offload()` hiçbir zaman istisna
  fırlatmaz.
- **Gece taramasında** (03:40, varsayılan kapalı): yükleme anında gidememiş
  dosyalar ve **küçük görseller** toplanır.

Küçük görsellerin neden taramaya bırakıldığı: onlar sayfa render'ı sırasında
üretilir, ve bir ziyaretçinin sayfasının kritik yoluna bloklayan bir yükleme
koymak yanlış olur.

### Mevcut bir siteyi taşımak

Taramayı açın ve `Şimdi tara` düğmesine birkaç kez basın; ya da geceyi bekleyin.
Tarama **imleçle** çalışır: her koşu kaldığı yerden devam eder, sona ulaşınca
başa döner. Kırk bin dosyalı bir kütüphanede her gece ilk iki yüz dosyayı
yeniden kontrol edip gerisine hiç ulaşmamayı önleyen şey budur.

---

## 4. Yedek hedefi

Aynı diskte duran yedek, yedek değildir. Sunucuyu kaybettiğinizde — yani insanların
yedeği asıl bu yüzden aldığı olayda — işe yaramaz.

Burada, projenin geri kalanının aksine, **hata gürültülüdür**: başarısız bir
yükleme operatörün ekranına çıkan bir hatadır, log'a düşen bir omuz silkme
değil. On bir ay önce sessizce durmuş bir site dışı yedek, hiç yedek
almamaktan **daha kötüdür**: size bakmadan geçen bir yıl satmıştır.

### `Yerel kopyayı koru` kapalıyken

5 GB'lık bir pakette meşru bir tercihtir. Yerel arşiv **yalnızca** karşıya
yüklendikten *ve karşıdan geri okunarak doğrulandıktan* sonra silinir. 200
döndüren ama gövdesi yarıda kesilmiş bir PUT, aksi hâlde tek sağlam kopyayı yok
ederdi.

### Silme iki ayrı düğmedir

Yerel arşivi silmek uzak kopyaya **dokunmaz**. İki kopya, birini kaybetmek
hayatta kalınabilir olsun diye vardır; dar diskte yer açan bir operatörün,
"sil"in ağın öbür ucuna uzanıp asıl amaç olan kopyayı da aldığını keşfetmesi
kabul edilemez.

---

## 5. CDN

**Tek kural: `cdn.base_url`, `/uploads` bölümünün YERİNE geçer.**

| Kurulum | Yazılacak adres |
|---|---|
| Origin'in önünde pull-zone (Cloudflare, BunnyCDN, Fastly) | `https://cdn.siteniz.com/uploads` |
| Herkese açık bucket (R2/S3/Spaces) | `https://pub-xxxx.r2.dev` |

Tek kuralın ikisini birden karşılaması, aradaki farkın tam olarak dosyaların
nerede köklendiği olmasından; bunu da yalnızca siz bilirsiniz.

**CDN, offload'dan bağımsızdır.** Pull-zone hiçbir yükleme gerektirmez: ıskada
bu origin'den çeker ve sonucu önbelleğe alır. CDN için bucket şartı koşmak, en
ucuz ve en yaygın kurulumu CPalius'un ifade edemediği kurulum yapardı.

Yalnızca `https` kabul edilir. `http` veya şemasız bir adres kaydedilmez ve
ekran bunu söyler — sitedeki her `<img>` etiketine giren bir alan adı konusunda
katı olmak, kırık görsellerden öğrenmekten iyidir.

### `Yalnızca görseller` (varsayılan açık)

uploads içine düşen diğer şey belgelerdir, ve bir CDN adresinden servis edilen
PDF, origin'in erişim kurallarını geride bırakmış bir PDF'tir —
`public/uploads/.htaccess` onun hakkında ne yapıyorduysa artık geçerli değildir.

---

## 6. Kimlik bilgileri nerede duruyor

`cp_settings` içinde, `type: 'password'` alanlar `SettingSecretCodec` ile
**şifreli** olarak. Paylaşılan bir veritabanı dökümü bir bucket devralma
olmamalıdır.

Ekran sırları **hiçbir zaman geri basmaz**. Boş bırakılan parola alanı "kayıtlı
değeri değiştirme" demektir — Sistem Ayarları ekranıyla aynı sözleşme, ki ikisi
aynı davransın ve birini öğrenen diğerinde şaşırmasın.

Depolama ayarları genel Sistem Ayarları tablolarında **görünmez**
(`SettingDefinition::HIDDEN_MODULES`). Gerekçe düzen değil: sınama kapısı orada
yoktur, ve secret key'i yeniden sınamadan değiştirebilen bir tablo, operatöre
yapılandırılmış görünen ama sessizce hiçbir şey kabul etmeyen bir yedek hedefi
verirdi.

---

## 7. Sorun giderme

| Belirti | Neden |
|---|---|
| `SignatureDoesNotMatch` | Bölge yanlış, ya da path-style seçimi ters. AWS: kapalı, R2/MinIO: açık |
| Sınama "yazıldı ama geri okunamadı" diyor | Kimlik bilgileri PUT'a izin veriyor, GET/HEAD'e vermiyor. Bucket politikasına bakın |
| FTP kartı eklenti uyarısı veriyor | Hosting'de `ext-ftp` kapalı; S3/R2 kullanın |
| FTP bağlanıyor, yükleme takılıyor | Pasif mod kapalı |
| CDN açık ama görseller hâlâ `/uploads` | Adres `https` değil ya da biçimi reddedildi; ekranda kırmızı uyarı vardır |
| CDN adresi 404 veriyor | `/uploads` iki kez var. Pull-zone'da adres `.../uploads` ile biter, bucket'ta bitmez |
| Tarama hep aynı dosyaları sayıyor | İmleç sona ulaşıp başa dönmüştür; bu normaldir |
