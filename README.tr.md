# CPalius CMF

**PHP 8.4+ ve Symfony 7.4 LTS** üzerine kurulu yeni nesil **İçerik Yönetim Çerçevesi** (Content Management Framework).

CPalius, ağır CMS platformları ile sıfırdan yazılan framework’ler arasında durur. Kimlik doğrulama, yetkilendirme, dosya yönetimi, çok dillilik, yönetim paneli ve modüler genişleme sistemi hazır gelir — her projede tekerleği yeniden icat etmezsiniz, klasik CMS’lerin teknik borcunu da taşımazsınız.

Site: [cpalius.com](https://www.cpalius.com)

---

## Neden CPalius?

| Yaklaşım | Sorun |
|----------|--------|
| Klasik CMS (WordPress, Drupal) | Eklenti zengin; şema ağır, borç ve güvenlik yüzeyi büyük |
| Saf framework (Symfony, Laravel) | Temiz; ama auth, ACL, medya ve admin her seferinde yeniden yazılır |
| **CPalius** | Symfony seviyesinde çekirdek + hazır kurumsal bileşenler + güvenli modüller |

Bugün blog ve topluluk siteleri; yarın galeri, acente, CRM veya ERP tarzı uygulamalar için aynı temel kullanılabilir.

---

## Gereksinimler

- PHP 8.4 veya üzeri
- Composer 2
- MySQL 8+ veya MariaDB 10.6+
  (PostgreSQL ve SQLite ile kurulum henüz desteklenmiyor: depodaki migration
  dosyaları MySQL’e özgü DDL üretiyor. ORM katmanının kendisi taşınabilir.)
- Belge kökü `public/` olan bir web sunucusu

Çekirdek ve yönetim arayüzü için Node.js gerekmez (AssetMapper + bağımsız Tailwind).

---

## Hızlı başlangıç

> **İlk iş, commit korumasını kurun:** `git config core.hooksPath .githooks`
> Bir kaynak dosyayı boşaltan, %80’den fazla küçülten veya sır içeren
> commit’leri engeller. Bkz. `.githooks/pre-commit`.

```bash
composer install
cp .env.example .env   # ardından DATABASE_URL, APP_SECRET, token’ları ayarlayın
php cp-core/bin/console doctrine:migrations:migrate
php cp-core/bin/console cp:user:create-admin
```

Sanal host’un document root’unu `public/` yapın ve siteyi açın.

Faydalı komutlar:

```bash
php cp-core/bin/console cp:module:list
php cp-core/bin/console cp:module:activate ModuleName
php cp-core/bin/console cp:cron:run
php cp-core/bin/console cp:blog:seed-demo-content
```

---

## Proje düzeni

```
CPalius/
├── cp-core/        # Kernel, config, migration’lar, App\ ad alanı
├── cp-includes/    # Composer vendor (ayrı tutulur)
├── cp-content/     # Sizin alanınız: modüller, temalar, sync config, çeviriler
├── public/         # Web kökü (ön denetleyici + asset’ler)
└── composer.json
```

Ad alanları:

- `App\` → `cp-core/src/`
- `Modules\` → `cp-content/modules/`

Geliştirici kodu `cp-content/` içinde kalır. Sistem dosyaları `cp-core/` içindedir.

---

## Core Never Dies (Çekirdek düşmez)

Hatalı üçüncü parti kod tüm uygulamayı kilitlememelidir. CPalius çekirdeği bir işletim sistemi gibi korur:

1. **Statik modül listesi** — Aktif modüller `active_modules.php` dosyasına yazılır. Boot sırasında modül durumu için veritabanına bakılmaz.
2. **Çalışma zamanı karantina** — Modül boot / rota / hook / API hataları yakalanır; çekirdek ve AACP ayakta kalır.
3. **Aktivasyon öncesi kontrol** — Modül açılmadan önce izole süreçte container/YAML lint çalışır. Hata varsa aktivasyon iptal edilir.
4. **Kurtarma konsolu** — `/aacp/recovery` veritabanı düşse bile kullanılabilir (token `.env` içinde).

Hatalı modüller karantina günlüğüne yazılır ve AACP’den incelenebilir.

---

## Kutudan çıkanlar

### İçerik ve iş verisi

- **Node** — Sayfa, yazı vb.: başlık, slug, durum, dil için SQL kolonları + esnek JSON `data`
- **Düz alan indeksi** — Sorgulanabilir JSON alanları hızlı filtre için indekslenir
- **Çok dillilik** — Çekirdekte yerleşik (`UNIQUE(slug, locale)`, çeviri grupları)
- **Resource** — `#[CpResource]` ile iş kayıtları (yetki, çok kiracılı bayraklar, workflow — altyapı hazır)

### Yönetim yüzeyleri

- **Studio (`/admin`)** — İçerik işleri: yazılar, medya, menüler, forum, ana sayfa portalı
- **AACP (`/aacp`)** — Sistem konsolu: modüller, eklentiler, cron, hook’lar, API anahtarları, metrikler, yerelleştirme, önbellek, karantina, kurtarma

### Genişletilebilirlik

| Katman | Nasıl |
|--------|--------|
| Modüller | `cp-content/modules/` altında bağımsız Symfony bundle’lar |
| REST API | `#[CpApi]` metotları → `/api/...`, `X-CP-API-KEY` |
| Hook’lar | Flat-file `Hooks/` ve/veya `#[CpHook]` servisleri |
| Cron | DB işleri, `#[CpCronJob]` ve hook dosyaları — tek çalıştırıcı |
| Eklentiler | Tüm modülü kapatmadan alt özellik aç/kapa |
| Ayarlar | `#[CpSetting]` tanımları, veritabanından tembel yükleme |

### Güvenlik ve performans

- Yeteneğe (capability) dayalı erişim kontrolü (uygulama kodunda `ROLE_ADMIN` kontrolü yok)
- Roller YAML olarak `cp-content/config/sync/` içinde (Git ile taşınır); kullanıcılar veritabanında
- Sorgu kapsamı: `.own` / `.any` yetenekleri SQL `WHERE` koşullarına dönüşür
- Kayıt anında zengin metin XSS temizliği
- Geliştirme ortamında N+1 sorgu koruması
- SaaS tarzı izolasyon için kiracı SQL filtresi altyapısı

---

## Dahil modüller

| Modül | Amaç |
|-------|------|
| **Blog** | Node üzerinde yazılar, kategori/etiket, zamanlanmış yayın, genel API, hook ve eklenti |
| **Forum** | Bölüm, konu, gönderi, beğeni, şikayet, ban, rütbe, moderasyon |
| **Media** | Medya kütüphanesi ve görsel seçici (hash tabanlı depolama) |
| **Menu** | Ön yüz için adlandırılmış menüler ve öğeler |

Varsayılan tema: `cpalius-website` (portal, blog ve forum arayüzü; `tr` / `en`).

---

## Yol haritası

- İş kayıtları için workflow / durum makinesi
- İstek üzerine görsel türevleri (Imagine tarzı boru hattı)
- İlk somut `#[CpResource]` örneği + denetim günlüğü
- E-posta ve toplu işler için Messenger transport’ları
- AACP’de tema yöneticisi arayüzü

Ürün anlatımı için [cpalius.com](https://www.cpalius.com), mimari kurallar için [CPALIUS_MANIFESTO.md](./CPALIUS_MANIFESTO.md).

---

## Katkı

Manifestoya (özellikle **Core Never Dies** ve `cp-core` / `cp-content` ayrımına) saygı duyan fikirler, issue’lar ve pull request’ler memnuniyetle karşılanır.

---

## Lisans

Güncel lisans koşulları için bu depodaki `composer.json` / `LICENSE` dosyasına bakın.

---

**CPalius CMF** — [MEGABRE](https://www.cpalius.com) projesi · Kurucu: Ali Çömez ([slaweally](https://github.com/slaweally))

[English README](./README.md) · [GitHub](https://github.com/CPalius/CPalius-Core)
