# CPalius — Sürüm Yayınlama Rehberi

Yeni bir CPalius sürümü çıkarmak için baştan sona izlenecek adımlar.
Bu belge bakımcılar içindir; **dağıtım paketine girmez** (bkz. §7).

İlgili kaynaklar:

- Sürüm sabiti: [`cp-core/src/Core/Version/CpVersion.php`](../cp-core/src/Core/Version/CpVersion.php)
- Kontrol servisi: [`cp-core/src/Core/Version/ReleaseChecker.php`](../cp-core/src/Core/Version/ReleaseChecker.php)
- Güncelleyici: [`cp-core/src/Core/Version/CoreUpdater.php`](../cp-core/src/Core/Version/CoreUpdater.php)
- Yama sistemi: [`PatchChecker`](../cp-core/src/Core/Version/PatchChecker.php) · [`PatchManifest`](../cp-core/src/Core/Version/PatchManifest.php) · [`PatchInstaller`](../cp-core/src/Core/Version/PatchInstaller.php)
- Manifest üreteci: [`docs/tools/patch-manifest.php`](tools/patch-manifest.php)
- Sürüm deposu: <https://github.com/CPalius/version> (yerel klon: `C:\laragon\www\cpalius-version`)
- Kaynak deposu: <https://github.com/CPalius/CPalius-Core>

---

## Hangisi: tam sürüm mü, yama mı?

İki ayrı mekanizma var ve seçim boyuta değil **türe** bakar.

| | **Tam sürüm** (§1–§7) | **Yama** (§9) |
|---|---|---|
| Ne taşır | Bütün ağaç, tek ZIP | Yalnızca adı geçen dosyalar |
| Şema değişikliği | Olabilir | **Olamaz** |
| `cp-includes/vendor` | Güncellenir | **Dokunulamaz** (depoda yok) |
| Migration / update hook | Çalışır | Çalışmaz |
| `composer.json` değişikliği | Olabilir | Yazılabilir ama `composer install` çalışmaz — yani **yapmayın** |
| Tipik kullanım | Yeni özellik, MINOR/MAJOR | Hata düzeltmesi, güvenlik yaması, PATCH |

**Kural:** yeni bir bağımlılık, yeni bir tablo veya yeni bir kolon varsa tam
sürüm çıkarın. Yama, "bir dosyada mantık hatası vardı" durumu içindir.

---

## 0. Ortam kısayolları

Bu makinede hiçbiri PATH'te değil:

```bash
PHP="/c/laragon/bin/php/php-8.4.14-nts-Win32-vs17-x64/php.exe"
COMPOSER="/c/laragon/bin/composer/composer.phar"
ZIP="/c/Program Files/7-Zip/7z.exe"
```

`php-8.4.14-nts` sürümünü kullanın — `php.ini` ve `pdo_mysql` yalnızca onda var.
`8.5.x` derlemelerinde ikisi de yok, veritabanına dokunan komutlar patlar.

---

## 1. Sürüm numarasına karar verin

```
MAJOR . MINOR . PATCH [ . HOTFIX ]
```

| Segment | Ne zaman artar |
|---|---|
| **MAJOR** | Geriye dönük uyumsuz değişiklik — modül API'si kırılır, elle müdahale gerektiren şema değişikliği |
| **MINOR** | Yeni özellik, geriye dönük uyumlu |
| **PATCH** | Yalnızca hata düzeltmesi |
| **HOTFIX** | Yalnızca acil güvenlik yaması. Normal sürümlerde yazılmaz — `1.2.3`, asla `1.2.3.0` |

**Zorunlu kural:** numara PHP `version_compare()` ile sıralanabilmeli.
Güncelleme kancaları sürümleri bu fonksiyonla sıralar; ayrıştıramadığı bir değer
sessizce yanlış sıraya girer ve veri taşımalarını yanlış sırayla çalıştırır.

Doğrulama:

```bash
"$PHP" -r 'var_dump(version_compare("1.1.0","1.0.9",">"));'   # true olmalı
```

Kabul edilmeyen biçimler: `v1.0.0`, `1.0.0-beta`, `1`, `1.0.0.0.0`
(`ReleaseChecker` bunları reddeder).

---

## 2. Kodda sürümü yükseltin

**Tek yer** — `cp-core/src/Core/Version/CpVersion.php`:

```php
public const VERSION = '1.1.0';
public const RELEASED_AT = '2026-10-01';
public const CHANNEL = 'stable';
```

`.env` veya `.env.example` içinde sürüm **aranmaz**; `CP_APP_VERSION` 1.0.0'da
kaldırıldı. Gerekçe sabitin kendi docblock'unda yazılı.

### Veri taşıması gerekiyorsa: güncelleme kancası yazın

Şema değişikliği Doctrine migration'ıdır. Ama bir ayarı yeniden yazmak, bir
indeksi yeniden kurmak, yeni eklenen bir kolonu doldurmak gibi işler DDL ile
ifade edilemez — bunlar **güncelleme kancası** olur:

```php
final class ReindexQueryableFields implements UpdateHookInterface
{
    public function id(): string { return 'core.1_1_0.reindex_queryable_fields'; }
    public function version(): string { return '1.1.0'; }
    public function description(): string { return 'Yeni alan tipi için düz indeksi yeniden kurar'; }
    public function run(): ?string { /* ... */ }
}
```

Üç kural:

1. **`id()` asla değişmez.** Defterde "çalıştı" kaydı bu id ile tutulur;
   yeniden adlandırmak kancayı ikinci kez çalıştırır.
2. **`version()` bu sürümü söyler.** Birkaç sürüm atlanarak güncellenirse
   kancalar yine yayın sırasıyla uygulanır.
3. **Yine de idempotent olsun.** Defter kanca döndükten sonra yazılır; ortada
   çökerse bir sonraki denemede baştan çalışır.

---

## 3. Paketi üretin

Geliştirme ağacına dokunmadan, temiz bir kopya kurun:

```bash
SRC=/c/laragon/www/CPalius-CMF
DST=/c/laragon/www/cpalius          # önceki paketi önce silin

rm -rf "$DST" && mkdir -p "$DST"
cd "$SRC"
tar -c \
  --exclude='cp-core/var' \
  --exclude='cp-core/tests' \
  --exclude='cp-includes/vendor' \
  --exclude='*/Tests' \
  --exclude='public/cp-cache-fix.php' \
  --exclude='public/cp-sync-meta.php' \
  --exclude='public/cp-fix-migration-table.php' \
  --exclude='public/page-cache/*.html' \
  --exclude='public/uploads/*' \
  cp-core cp-content cp-includes public \
  composer.json composer.lock symfony.lock importmap.php .env.example LICENSE \
| tar -x -C "$DST"

# tar dışlaması bunları da eledi; ikisi de pakette OLMAK ZORUNDA
cp public/uploads/.htaccess public/uploads/.gitkeep "$DST/public/uploads/"
```

> `public/uploads/.htaccess` bir **güvenlik dosyasıdır** (yüklenen dosyaların
> çalıştırılmasını engelleyen beş katmanlı sertleştirme, SEC-01). Pakette
> unutulursa canlıda hiç var olmayan bir korumaya güvenilmiş olur.

### Bağımlılıklar ve varlıklar

```bash
cd "$DST"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
```

> Sondaki `cache:clear` hatası **beklenen** — pakette `.env` yok, olmamalı da.

Varlık derlemesi geçici bir `.env` ister. `prod` yerel makinede çalışmaz
(Redis/Memcached yok), bu yüzden `dev` ile derleyin — çıktı aynıdır:

```bash
cp .env.example .env
"$PHP" -r '$s=file_get_contents(".env");
  $s=preg_replace("/^APP_SECRET=.*$/m","APP_SECRET=".bin2hex(random_bytes(16)),$s);
  $s=preg_replace("/^APP_ENV=prod/m","APP_ENV=dev",$s);
  file_put_contents(".env",$s);'

"$PHP" cp-core/bin/console tailwind:build --minify --no-interaction
"$PHP" cp-core/bin/console asset-map:compile --no-interaction
```

> `tailwind:build` önce çalışmalı — `asset-map:compile` derlenmiş CSS'i arar ve
> yoksa hata verir. Tailwind ilk seferde ~112 MB'lık bir binary indirir ve onu
> `cp-core/var/` altına koyar; sonraki adımda zaten siliniyor.

### Geçici dosyaları temizleyin

```bash
rm -f .env                      # ASLA pakete girmez
rm -rf cp-core/var && mkdir -p cp-core/var && : > cp-core/var/.gitkeep
rm -f public/page-cache/.enabled     # çalışma zamanı bayrağı, panelden açılır
rm -f cp-core/bin/phpunit            # var olmayan yolu gösteren bozuk şim
```

### Paket denetimi — atlamayın

```bash
cd "$DST"
find . -name ".env" -o -name ".env.local" -o -name "*.sql" -not -path "./cp-content/*" \
  -o -name ".git" -o -name "phpstan*" -o -name "phpunit*"
# ^ BOŞ çıkmalı. cp-content altındaki .sql dosyaları modül migration'larıdır, kalır.

grep -rl "125478\|slaweally@" cp-core cp-content public .env.example 2>/dev/null
# ^ BOŞ çıkmalı (yerel DB parolası / kişisel e-posta sızıntısı)
```

### Arşivi oluşturun

```bash
cd /c/laragon/www
"$ZIP" a -tzip "cpalius-1.1.0.zip" cpalius -mx=7
```

Arşivde tek bir `cpalius/` üst klasörü olur — güncelleyici bunu tanır ve
içeriğe iner. Nokta ile başlayan dosyaları 7-Zip doğru işler; `Compress-Archive`
kullanmayın, gizli dosyaları atlayabilir.

### SHA-256 özetini alın

```bash
sha256sum cpalius-1.1.0.zip
# veya Windows: certutil -hashfile cpalius-1.1.0.zip SHA256
```

Bu değeri bir yere not edin — bir sonraki adımda gerekiyor.

---

## 4. Arşivi yayına koyun

Paketi indirilebilir bir adrese koyun. `ReleaseChecker` yalnızca şu ön eklere
izin verir (başkası sessizce yok sayılır):

- `https://github.com/CPalius/…`
- `https://raw.githubusercontent.com/CPalius/…`
- `https://www.cpalius.com/…`
- `https://cpalius.com/…`

Başka bir alan adı kullanacaksanız `ReleaseChecker::fetch()` içindeki liste
genişletilmelidir.

---

## 5. Sürüm deposunu güncelleyin — **sıra önemli**

```bash
cd /c/laragon/www/cpalius-version
git pull
```

**a) Sürüm notunu yazın:** `releases/1.1.0.md`

Şablon: [`releases/1.0.0.md`](https://github.com/CPalius/version/blob/main/releases/1.0.0.md)
Başlıklar: *Yenilikler · Düzeltmeler · Kırılmalar · Yükseltme notları · Bilinen kısıtlar*

> **Dil kuralı:** `<sürüm>.md` **daima İngilizcedir** ve varsayılandır.
> Çeviriler yanına `<sürüm>-<dil>.md` olarak konur — örneğin `1.1.0-tr.md`.
>
> `ReleaseChecker::fetchNotes()` önce panelin aktif diline göre
> `<sürüm>-<dil>.md` ister, bulamazsa `<sürüm>.md`'ye düşer. `tr_TR` gibi
> bölgesel bir dil önce tam hâliyle, sonra yalnızca dil koduyla denenir.
> Çevirisi olmayan eski bir sürüm bu sayede hiçbir şey yerine İngilizce
> gösterir.
>
> `versions.json` ve `latest.json` **her zaman varsayılan (İngilizce) dosyayı**
> işaret eder; çeviriler oralarda listelenmez, kendiliğinden bulunur.
>
> İki dosya birbirine bağlansın: İngilizcede `Turkish: [1.1.0-tr.md](...)`,
> Türkçede `English: [1.1.0.md](...)`.

> **Markdown tablosu kullanmayın.** Bu notlar AACP panelinde, ham HTML'i
> kaçıran küçük bir CommonMark alt kümesiyle render ediliyor. Başlık, liste,
> vurgu, kod ve bağlantı destekleniyor; **tablo desteklenmiyor** ve operatöre
> ham `| boru | metin |` olarak görünür. Kalın etiketli liste kullanın.

**b) `versions.json` dizinine ekleyin** — yeni girdi listenin **başına**.

**c) `latest.json`'u EN SON güncelleyin:**

```jsonc
{
  "schema": 1,
  "channel": "stable",
  "version": "1.1.0",
  "released_at": "2026-10-01",
  "critical": false,              // true ise panel uyarısı kapatılamaz olur
  "requires": {
    "php": ">=8.4",
    "upgrade_from": ">=1.0.0"
  },
  "notes": {
    "url": "https://github.com/CPalius/version/blob/main/releases/1.1.0.md",
    "raw": "https://raw.githubusercontent.com/CPalius/version/main/releases/1.1.0.md"
  },
  "download": {
    "zip": "https://www.cpalius.com/downloads/cpalius-1.1.0.zip",
    "sha256": "3. adımda aldığınız özet"
  }
}
```

> **Neden en son:** bu dosya değiştiği an sahadaki tüm kurulumlar yeni sürümü
> görmeye başlar. Notlar henüz yayında değilken işaretçiyi ileri almak,
> kullanıcıları var olmayan bir sürüm notuna yönlendirir.

> **`sha256` opsiyonel değil.** Boş bırakılırsa güncelleyici butonu hiç
> göstermez — ve göstermesi de istenmez: doğrulanamayan bir arşivi kurmak,
> ele geçirilmiş bir aynayı sorgusuz çalıştırmakla aynı şeydir.

```bash
git add -A && git commit -m "1.1.0" && git push
```

---

## 6. Doğrulayın

```bash
cd /c/laragon/www/CPalius-CMF
"$PHP" cp-core/bin/console cp:cron:run-virtual core.release_check
```

Beklenen çıktı: `Up to date (1.1.0).` — yani kurulu sürümünüz yayınlananla eşit.

Eski bir kurulumdan bakıyorsanız: `Update available: 1.1.0 (running 1.0.0).`

> `raw.githubusercontent.com` yaklaşık **5 dakika önbellekler**. Push'tan hemen
> sonra eski içerik görürseniz beklemek yeterli; commit hash'iyle doğrudan
> kontrol edebilirsiniz:
> `curl -s https://raw.githubusercontent.com/CPalius/version/<hash>/latest.json`

AACP → Güncellemeler sayfasını açın: sürüm kartı, son kontrol zamanı ve — paket
ile özet doldurulduysa — **güncelle butonu** görünmeli.

---

## 7. Canlıya alma (cpalius.com — SSH yok)

Canlı ortam paylaşımlı hosting: **FTP + phpMyAdmin**, konsol yok.

1. Değişen/yeni dosyaları FTP ile yükleyin.
2. **`cp-core/var/cache/prod/` içini silin.** Klasör kalsın, içi boşalsın.

İkinci adım şart: Symfony prod'da konteyneri kendiliğinden yeniden derlemez.
Yeni servisler, parametreler, Twig fonksiyonları ve cron görevleri önbellek
temizlenmeden **görünmez** — dosyaları atıp bırakırsanız hiçbir şey değişmez.

> Doctrine üstverisi prod'da **Redis**'te de tutulur ve FTP oraya erişemez.
> Entity/şema değiştiren bir sürümde `cache:pool:clear --all` gerekir; bunu
> çalıştıracak konsol olmadığı için token korumalı tek kullanımlık bir
> `public/*.php` betiği ile yapın ve **kullandıktan sonra silin**.

`docs/` klasörü pakete girmez: §3'teki `tar` komutu arşivlenecek yolları tek tek
sayar (`cp-core cp-content cp-includes public` + kök dosyaları), listede olmayan
hiçbir şey pakete alınmaz. Canlıya da yüklemeyin.

---

## 8. Bir şeyler ters giderse

### Güncelleyici hata verdi

`CoreUpdater::apply()` üzerine yazma sırasında patlarsa **yedekten geri yükler**
ve hata fırlatır. Kurulum eski sürümde kalır — kabul edilebilir tek hata modu
budur. Panel hatayı kırmızı kutuda gösterir.

### Güncelleme başarılı ama site bozuk

Önceki sürümün dosyaları duruyor:

```
cp-core/var/update/backup-<sürüm>/
```

İçinde `.manifest.json` var — yazılan dosyaların listesi. Elle geri almak için
yedekteki dosyaları proje köküne kopyalayın, ardından `cp-core/var/cache/<env>`
içini silin.

Yedek **bilerek silinmiyor**; arşiv ve staging siliniyor ama yedek kalıyor.

### "Buton görünmüyor"

Kartın altında sebebi yazar. Olası nedenler:

| Neden | Çözüm |
|---|---|
| `download.zip` boş | `latest.json`'a paket adresini yazın |
| `download.sha256` boş | Özeti hesaplayıp yazın |
| PHP `zip` eklentisi yok | Hosting'de etkinleştirin |
| `cp-core` yazılabilir değil | FTP'den izinleri düzeltin |
| Kurulum zaten güncel | Normal davranış |

### "Son kontrol" boş

Henüz başarılı kontrol yapılmamış. Sayfayı açmak `refreshIfStale(900)` tetikler;
cron (her gün 04:17) de yapar. Ağ hatasında **önceki sonuç korunur** — açık bir
güvenlik uyarısı bir kesinti yüzünden silinmez.

---

## 9. Yama yayınlama (dosya bazlı güncelleme)

Tek bir dosya değişti ve sahadaki herkesin bunu alması gerekiyor. Tam paket
üretmeden, yalnızca o dosyayı yayınlayın.

### 9.0 Nasıl çalıştığı

Sahadaki kurulum, dosya gövdelerini **kaynak deposundan ham olarak** çeker:

```
https://raw.githubusercontent.com/CPalius/CPalius-Core/v<sürüm>/<yol>
```

Bu, depodaki ağaç düzeni kurulumdaki düzenle birebir aynı olduğu için çalışır
(`cp-core/`, `cp-content/`, `public/` + kök dosyalar). Pakette olmayan tek şey
`cp-includes/vendor` — o da zaten depoda yok, ve `ProtectedPaths` bir yamanın
oraya yazmasını reddeder.

Her dosya indirildikten sonra **SHA-256 doğrulanır**; biri tutmazsa proje
ağacına tek bayt yazılmaz. Yarısı uygulanmış bir yama, kimsenin test etmediği
bir sürüm karışımı demektir.

### 9.1 Sürümü yükseltin ve commit'leyin

Yama da bir sürümdür. `CpVersion::VERSION` **mutlaka** artmalı; `PatchManifest`
yamanın `CpVersion.php` taşımasını zorunlu tutar ve `PatchInstaller`, indirilen
dosyanın sabitinin manifestteki sürümü söylediğini doğrular.

> Bu ikili kontrol olmasaydı, 1.1.1 ilan edip 1.1.0 kodu gönderen bir manifest
> uygulanır, çalışan sürüm yerinde kalır ve aynı yama sonsuza kadar her kontrolde
> yeniden teklif edilirdi.

### 9.2 Etiketleyin ve push'layın

```bash
cd /c/laragon/www/CPalius-CMF
git commit -am "1.1.1: <kısa özet>"
git tag v1.1.1
git push origin main --tags
```

Etiket **önce** gitmeli: manifest ona işaret eder, ve etiket yokken yayınlanan
bir manifest herkesi 404'e yollar.

### 9.3 Manifesti üretin

Elle yazmayın — digest'ler işin güvenlik temelidir.

```bash
PHP="/c/laragon/bin/php/php-8.4.14-nts-Win32-vs17-x64/php.exe"

"$PHP" docs/tools/patch-manifest.php 1.1.1 1.1.0 "Blog yorum sayacı düzeltildi" \
  cp-core/src/Core/Version/CpVersion.php \
  cp-content/modules/Blog/Service/BlogCommentService.php \
  > /c/laragon/www/cpalius-version/patches/1.1.1.json
```

Değişenleri git'e saydırabilirsiniz:

```bash
git diff --name-only v1.1.0..v1.1.1 \
  | "$PHP" docs/tools/patch-manifest.php 1.1.1 1.1.0 "..." - \
  > /c/laragon/www/cpalius-version/patches/1.1.1.json
```

Silinen bir dosya için yolun başına `-` koyun: `-cp-content/modules/Blog/Old.php`

Araç, kurulumun reddedeceği her şeyi peşinen reddeder: vendor, uploads, var,
`.env`, çalışma ağacında olmayan yol, tekrarlanan yol, ve sabiti manifestle
uyuşmayan `CpVersion.php`.

### 9.4 `patches/index.json` dizinine ekleyin — **en başa**

```jsonc
{
  "schema": 1,
  "patches": [
    {
      "version": "1.1.1",
      "base": "1.1.0",              // HANGİ sürümden yükselttiği; zincir budur
      "released_at": "2026-09-20",
      "critical": false,
      "summary": "Blog yorum sayacı düzeltildi",
      "manifest": "https://raw.githubusercontent.com/CPalius/version/main/patches/1.1.1.json"
    }
  ]
}
```

> **`base` zincirin kendisidir.** Panel yalnızca `base` değeri çalışan sürüme
> eşit olan yamayı teklif eder. 1.1.0'daki bir siteye 1.1.3 teklif etmek,
> 1.1.1 ve 1.1.2'deki dosya değişikliklerini atlamak olurdu — ve yama diff değil
> **tam dosya** taşıdığı için sonuç, bir kısmı üç sürüm ileri bir kısmı üç sürüm
> geri, hangisinin hangisi olduğunu hiçbir yerin kaydetmediği bir ağaç olurdu.
>
> Yani: **her ara sürüm için bir yama yayınlayın**, ya da tam sürüm çıkarın.

### 9.5 `latest.json`'u da güncelleyin

Yama yine de bir sürümdür; `latest.json` içindeki `version` değeri güncellenmezse
tam güncelleyici sahadaki 1.1.1'leri "güncel değil" saymaz ama yeni kurulumlar
eski ZIP'i indirir. Sürüm notunu da yazın (`releases/1.1.1.md`).

### 9.6 Doğrulayın

```bash
"$PHP" cp-core/bin/console cp:patch --refresh
```

Bekleyen yamayı, dosya listesini ve digest'leri basar; hiçbir şey yazmaz.
Uygulamak için `--apply` gerekir ve etkileşimli oturumda ayrıca onay sorar.

Panelde: **AACP → Güncellemeler → Dosya yamaları**. Kart, değişecek her dosyayı
ve kısaltılmış digest'ini listeler. Uygulama **yalnızca** operatör düğmeye
bastığında olur — cron kontrolünden `PatchInstaller::apply()`'a giden hiçbir kod
yolu yoktur. Otomatik uygulamak, CPalius GitHub organizasyonunu ele geçirenin
sahadaki her kurulumu operatör olmadan ele geçirmesi demek olurdu.

### 9.7 Bir şey ters giderse

Yazmadan önceki hâller burada durur:

```
cp-core/var/update/patch-backup-<sürüm>/
```

İçinde `.manifest.json` vardır. Yazma sırasında bir hata olursa `PatchInstaller`
zaten kendisi geri yükler ve kurulum eski sürümde kalır.

---

## Hızlı kontrol listesi

- [ ] `CpVersion::VERSION` ve `RELEASED_AT` güncellendi
- [ ] Gerekiyorsa güncelleme kancası yazıldı (`id()` benzersiz, idempotent)
- [ ] Paket üretildi, `.env` yok, `public/uploads/.htaccess` var
- [ ] Denetim komutları boş çıktı verdi
- [ ] Arşiv oluşturuldu, SHA-256 alındı
- [ ] Arşiv izin verilen bir adrese yüklendi
- [ ] `releases/<sürüm>.md` yazıldı — İngilizce, tablosuz
- [ ] Çeviri gerekiyorsa `releases/<sürüm>-tr.md` yazıldı ve iki dosya birbirine bağlandı
- [ ] `versions.json` başına eklendi
- [ ] `latest.json` **en son** güncellendi (zip + sha256 dolu)
- [ ] `cp:cron:run-virtual core.release_check` doğru sonucu verdi
- [ ] Canlıya yüklendi ve `cp-core/var/cache/prod/` boşaltıldı

### Yama için (§9)

- [ ] `CpVersion::VERSION` artırıldı ve commit'lendi
- [ ] `git tag v<sürüm>` atıldı ve `--tags` ile push'landı
- [ ] `patches/<sürüm>.json` **araçla** üretildi (elle yazılmadı)
- [ ] `patches/index.json` başına eklendi, `base` bir önceki sürüm
- [ ] Atlanan ara sürüm yok (her adım için bir yama)
- [ ] `cp:patch --refresh` yamayı ve dosya listesini doğru gösterdi
