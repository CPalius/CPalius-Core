# Portal modülü: yapılacaklar listesi (askıda)

**Durum:** Askıya alındı. Bir sonraki talimat gelene kadar Portal üzerinde çalışılmıyor.
**Kaynak:** agasar.org Laravel yedeği (`Eksik-Modüller/`). **Hedef:** `cp-content/modules/Portal/` (`Modules\Portal\PortalModule`, tablo öneki `cp_portal_`), tema `cp-content/themes/agasar/`.
**İlgili belgeler:** `raporlar/agasar-cpalius-gecis-plani.md` (geçiş planı, parite maddeleri §6), `raporlar/PORTAL-GELISTIRME-NOTU.md` (teknik notlar ve kararlar).

## 1. Mevcut durum (özet)

Çalışanlar (geliştirme sitesinde `/tr/...` altında 200 dönüyor):

- İskelet, `module.json` (Media, Menu, Pages, Seo gerekli), `001_portal.sql` (8 tablo, 2 sözlük, 30 Field tanımı), 6 Node türü (`portal_news/article/announcement/event/person/album`).
- Varlıklar: anket (+oy), sözlük, reklam, yorum, gönderim, medya, görüntülenme sayacı.
- Ön yüz: ana sayfa, 6 tür için liste ve detay, sözlük, profil, "Bize Gönder" formu, yorum ve anket POST uçları, eski adresler için 301 yönlendirme.
- Servisler, Twig uzantısı, ~58 ayar, SEO sağlayıcısı + site haritası kaynağı, arama sağlayıcısı, `cp:portal:seed-demo`.
- `agasar` teması (derlenmiş Tailwind CSS, vendor dosyaları yerelde).
- Container, Twig ve YAML lint temiz.

Hiç doğrulanmayanlar:

- `Tests/Integration/PortalFlowTest.php` yazıldı, **hiç çalıştırılmadı**.
- Yönetim ekranları (`PortalNodeAdminController`, `admin/nodes/*`) giriş yapılarak denenmedi.
- Yorum, anket oyu ve "Bize Gönder" gönderimi gerçekten denenmedi.

## 2. Yapılacaklar

Öncelik sırası önerisi: A → B → C → D → E. İşaretler: `[ ]` yapılmadı.

### A. Doğrulama (önce bunlar)

- [ ] Yönetim ekranlarını giriş yaparak sına: 6 türün liste, oluştur, düzenle, sil, yayınla, medya yükleme.
- [ ] POST akışlarını sına: yorum (onay bekler, onaylanınca görünür), anket (tek oy, eski toplamlar), "Bize Gönder" (4 tür: etkinlik, fotoğraf, haber, makale).
- [ ] Yalnızca **yeni** yazılan `PortalFlowTest` çalıştırılır (kullanıcı kuralı: tam paket yok, eski testler yok).
- [ ] Pasif/aktif döngüsü: modülü kapatıp açınca veri korunuyor mu, migration idempotent mi.

### B. Eksik özellikler

- [ ] **Gönderim yayınlama ekranı:** `PortalSubmissionService::publish()` hazır, yönetici "Yayınla / Reddet / Spam" ekranı ve controller yok.
- [ ] **Roller ve yetkiler:** `capabilities.yaml` 50 satır üretildi ama `ModuleInstaller` yalnızca admin'e veriyor. Editor, author, moderator için ve üyelerin yorum yapması için yetki atamaları (Showcase modülü örnek alınabilir).
- [ ] **Nöbetçi eczane:** yönetici sayfasında "yenile" düğmesi (`PortalPharmacyClient`, CollectAPI anahtarı ayarlarda).
- [ ] **Eski `/storage/*` yönlendirmesi:** Laravel dosya adreslerini `/uploads/...` konumuna 301.
- [ ] **Yandex doğrulama dosyası:** köke servis edilmesi (ayardan gelen değerle).
- [ ] Yorum ve gönderim toplu işlemleri: AACP "Kaynaklar" ekranı yeterli mi karar ver, yetmiyorsa özel liste.

### C. Veri göçü

- [ ] `cp:portal:import` komutu: Laravel MySQL kaynağı (`--dsn`), yüklenen dosyalar (`--uploads`).
- [ ] Haber, makale, duyuru, etkinlik, kişi, albüm, anket, sözlük, reklam, yorum, gönderim kayıtları.
- [ ] Menüler, sayfalar, site ayarları, ziyaret sayacı göçü.
- [ ] Komut idempotent olsun (iki kez çalışınca kopya üretmesin) ve kuru çalıştırma (`--dry-run`) desteklesin.
- [ ] Kaynaktaki medyayı Media modülüne aktar, alan değerlerindeki asset kimliklerini eşle.

### D. Tema ve parite

- [ ] Son şablon değişikliklerinden sonra Tailwind CSS'i yeniden derle (`tools/tailwind.config.js`, `tools/input.css`).
- [ ] Parite denetimi: plan belgesi §6 maddelerini tek tek işaretle (sayfalar, form alanları, SEO etiketleri, site haritası, arama, geri bildirim mesajları).
- [ ] Görsel karşılaştırma: kaynak site ile yan yana, mobil ve masaüstü.
- [ ] Tema dış bağımlılık içermemeli (CDN yok); mevcut vendor dosyaları yerelde.

### E. Kalite ve teslim

- [ ] Her düzenlemeden sonra modüldeki tüm PHP dosyalarına `php -l` (sözdizimi hatası modülü sessizce karantinaya alıyor).
- [ ] Modül sürümü ve değişiklik notu.
- [ ] Geliştirme notunu (`raporlar/PORTAL-GELISTIRME-NOTU.md`) güncel tut.

## 3. Geliştirme ortamında geri alınacaklar

Bunlar yalnızca yerel veritabanını etkiliyor, hâlâ geri alınmadı:

- [ ] `cp_settings` içinde `core.active_theme=agasar` yapıldı. Eski değer: satır **yoktu** (varsayılan `cpalius-website`). Geri almak için satırı sil.
- [ ] `cp_settings` içinde `homepage.mode=agasar` yapıldı. Eski değer: `portal`.
- [ ] Geçici yönetici kullanıcı `ptest` (e-posta `ptest@example.test`, id 5388) silinecek. Geçici parola kayıtlı; kullanıcı silinince geçersiz olur.
- [ ] `cp:portal:seed-demo` ile üretilen örnek içerik gerçek veri geçmeden önce temizlenecek.

## 4. Teknik notlar (devam ederken lazım)

- PHP: `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe` (web ile aynı). Komut: `php cp-core/bin/console <komut>`.
- Yerel site: `http://cpalius-project.test`. Modül rotaları `/{_locale}` ön ekini ister; eski adresler `LegacyRedirectController` ile 301.
- Modül aktivasyonu web'den artık arka planda doğrulanıyor (Law 2.2); birkaç saniye sürer, hata olursa modül karantinaya düşer.
- SQL migration: yorum içinde `;` olmasın, BOM/CRLF olmasın (UTF-8, LF), yayınlanmış dosya değişmez.
- Select alan `choices` biçimi `değer|etiket`; `rich_text` değeri `['value','format']`; `image` asset id; `date`/`datetime` ATOM metni.
- Anonim formlar oturum açmaz: CSRF yerine aynı-köken denetimi, `FloodService` ve captcha. Geri bildirim `?ok=` / `?err=` ile.
- Alpine CSP derlemesi: `x-data` adları `agasar.js` içinde; satır içi ifade yazılmaz.
- Çekirdeğe (`cp-core`, `cp-includes`) dokunulmaz; Botble yalnızca fikir kaynağıdır, kod kopyalanmaz.
- `.env` okunmaz.
- Dosya yazmak için Bash heredoc yerine Write/Edit araçları kullan (kaçış sorunları).

## 5. Bilinçli olarak yapılmayanlar

- Laravel'deki "Server by" rozeti taşınmadı (üçüncü taraf barındırma reklamı). İstenirse footer'a eklenir.
- Top-pages ve cihaz raporları taşınmıyor; analitik VisitorStats + Yandex ile.
- Laravel giriş/kayıt sistemi taşınmadı: kimlik doğrulama çekirdekte, modül yalnızca çekirdeğin rotalarına bağlanır.
