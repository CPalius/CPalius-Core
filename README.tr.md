# CPalius CMF

**PHP 8.4 ve Symfony 7.4 LTS üzerinde çalışan modüler bir uygulama çekirdeği.**

CPalius klasik bir CMS değildir. Ciddi her web uygulamasının ihtiyaç duyduğu parçaları hazır verir: kimlik doğrulama, yetenek tabanlı erişim, içerik ve iş kaydı modelleri, medya, çok dillilik, yönetim paneli, imzalı güncelleme kanalı ve modül/tema sistemi. Blog, forum, portal, pazar yeri, rezervasyon gibi ürünler bunun üzerine modül olarak kurulur.

Site: [cpalius.com](https://www.cpalius.com) · Güncel kararlı sürüm: **2.2.69** · [English](./README.md)

---

## Bu depoda ne var

Bu depo **çekirdektir**; tek başına çalışsın diye küçük bir başlangıç seti de içerir:

| Dahil | Ne |
|---|---|
| Çekirdek | Kernel, AACP yönetim paneli, güvenlik, güncelleme sistemi, göçler (`cp-core/`) |
| Modüller | Blog, Forum, Media, Menu, Messages, Pages, Widget (`cp-content/modules/`) |
| Tema | `cpalius-website`, varsayılan tema (`cp-content/themes/`) |

Diğer modül ve temalar ayrı depolardadır:

- Modüller: [CPalius/Modules](https://github.com/CPalius/Modules)
- Temalar: [CPalius/Themes](https://github.com/CPalius/Themes)

---

## Gereksinimler

- PHP 8.4 veya üzeri
- MySQL 8+ ya da MariaDB 10.6+ (PostgreSQL ve SQLite kurulum için henüz desteklenmez; göçler MySQL'e özgü DDL üretir)
- Belge kökü `public/` olan bir web sunucusu (Apache `.htaccess` dahil)
- Composer 2 yalnızca kaynaktan kurulumda gerekir; yayın zip'inde `cp-includes/vendor` hazır gelir

Çekirdek ve yönetim arayüzü için Node.js gerekmez.

---

## Kurulum

**Yayın zip'inden:** yükleyin, siteyi açın, kurulum sihirbazını izleyin (gereksinimler, boş veritabanı, site adı, ilk yönetici). Sihirbaz bitince kendini siler.

**Komut satırından:**

```bash
composer install
cp .env.example .env        # DATABASE_URL ve APP_SECRET'i girin
php cp-core/bin/console doctrine:migrations:migrate
php cp-core/bin/console cp:user:create-admin
```

Barındırma belge kökünü `public/` yapmaya izin vermiyorsa kökteki `.htaccess` `public/` klasörünü sunar ve ağacın geri kalanını kapatır.

Sık kullanılan komutlar:

```bash
php cp-core/bin/console cp:module:list
php cp-core/bin/console cp:module:activate <Ad>
php cp-core/bin/console cp:cron:run
php cp-core/bin/console cp:doctor            # ortam, güvenlik ve performans denetimi
```

---

## Dizin yapısı

```
cp-core/       Kernel, yapılandırma, göçler, App\ ad alanı
cp-includes/   Composer vendor (yalıtılmış)
cp-content/    Sizin alanınız: modüller, temalar, senkron yapılandırma, çeviriler
public/        Web kökü
```

Ad alanları: `App\` → `cp-core/src/`, `Modules\` → `cp-content/modules/`.

---

## Arkasındaki fikirler

- **Çekirdek ölmez.** Açılışta hata veren modül karantinaya alınır; çekirdek ve yönetim paneli çalışmaya devam eder. `/aacp/recovery` adresinde veritabanından bağımsız bir kurtarma konsolu vardır.
- **İki veri modeli.** İçerik için *Node* (revizyon, çeviri, iş akışı, alanlar). İş kayıtları için *Resource*; bir Doctrine varlığını `#[CpResource]` ile işaretleyin, liste, form ve yetkiler hazır gelsin.
- **Rol değil yetenek.** Erişim adlandırılmış yeteneklerle verilir (`forum.post.edit.own`). Roller YAML'dır ve Git ile taşınır. Siteyi ele geçirebilecek yetenekler arayüzde "Kısıtlı" diye işaretlenir.
- **Yapılandırma kodda, ayarlar panelde.** `#[CpSetting]`, `#[CpAdminMenu]`, `#[CpHook]`, `#[CpApi]`, `#[CpCronJob]`.
- **İddia değil ölçüm.** Performans ve güvenlik araçları ürünle birlikte gelir.

---

## Güvenlik

- bcrypt (maliyet 13) parolalar, girişte yeniden hash, sızıntı denetimi, giriş kısıtlama, captcha, IP yasaklama ve yerleşik WAF
- İki aşamalı doğrulama (TOTP, e-posta kodu, kurtarma kodları) ve hassas panel işlemlerinde kimlik yeniden doğrulama
- AES-256-GCM ile mühürlü sırlar ve kesintisiz anahtar döndürme (`cp:secrets:rotate`)
- Nonce'lu CSP ve rapor alıcı, HSTS, güvenilir host listesi; `cp:security:csp-suggest` ihlal raporlarını öneriye çevirir
- Yükleme denetimleri (gerçek MIME, çift uzantı, yürütme engeli); SVG yükleme bilerek reddedilir
- Ed25519 imzalı güncelleme bildirimi ve eklenti dizini, sha256 doğrulamalı paketler, sürüm geri alma saldırısına ret, eklentiler için imzalı güvenlik duyuruları

Güvenlik açıklarını özel olarak bildirin; bkz. [SECURITY.md](./SECURITY.md).

---

## Performans

- Misafir sayfaları dosya olarak saklanır ve PHP başlatılmadan web sunucusu tarafından sunulur; temizlemede eskiyi sun, arkada yenile
- Etiket tabanlı geçersiz kılma (sayfa başına yaklaşık 3 ms)
- Araçlar: `cp:perf:probe`, `cp:perf:routes`, `cp:doctor --only=performance` ve geliştirme modunda N+1 sorgu bekçisi

100 bin kullanıcı, 200 bin mesaj ve 100 bin rezervasyonlu veritabanındaki rakamlar tek istek ölçümüdür, eşzamanlı yük testi değildir.

---

## Güncelleme

Çekirdek güncellemesi panelden uygulanır: imzalı bildirim → indirme → sha256 doğrulama → atomik kurulum → göçler → önbellek yenileme. Modüller ve temalar imzalı eklenti kataloğu üzerinden ayrı sürümlenir.

---

## Dokümantasyon

[cpalius.com/tr/docs](https://www.cpalius.com/tr/docs) · Mimari kurallar için [CPALIUS_MANIFESTO.md](./CPALIUS_MANIFESTO.md).

## Lisans

Kapalı kaynak. Tüm hakları saklıdır. Bkz. [LICENSE](./LICENSE).

---

[MEGABRE](https://www.cpalius.com) projesi · Kurucu: Ali Çömez ([slaweally](https://github.com/slaweally))
