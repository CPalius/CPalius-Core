# CPalius CMF — Çekirdek Yol Haritası ve Eksik Analizi

> **Amaç:** CPalius'u Drupal / TYPO3 / WordPress / ProcessWire'ın çekirdekteki en iyi
> yanlarını alıp daha modern, güvenli ve performanslı bir **melez uygulama framework'ü**
> hâline getirmek. CPalius bir "dil" gibi düşünülmelidir: CMS, CRM, ERP, video sitesi,
> dosya paylaşım, hosting paneli, müşteri yönetimi — hepsinin altından kalkabilmeli.
>
> **Bu dosya canlı bir belgedir.** Her oturumda güncellenir. "Nerede kaldık?" sorusunun
> tek cevabı burasıdır. İşaretleme: `[ ]` yapılmadı · `[~]` devam ediyor · `[x]` bitti.
>
> İlgili belgeler: `CPALIUS_MANIFESTO.md` (mimari anayasa) ·
> `cp-core/src/Core/Portal/WhitepaperContent.php` (teknik whitepaper) ·
> `~/.claude/plans/compressed-wishing-aho.md` (Field API Faz A planı).
>
> **KAPSAM SINIRI (kullanıcı kararı, 2026-09-11):** CPalius sadece web sitesi değil; CRM,
> ERP, hosting paneli, dosya paylaşım, müşteri yönetimi gibi çok farklı uygulama tipleri için
> **çekirdek**. "Site builder" (kodsuz sayfa/liste/blok kurma UI'ı gibi web-sitesi-merkezli,
> son-kullanıcı-için CMS özellikleri) bilinçli olarak **çekirdek kapsamı dışı** — gereksiz yük
> bindirir ve projenin amacından saptırır. Bir tier maddesi önerilirken bu soru sorulur: "bu
> özellik yalnızca web sitesi kurmak için mi var, yoksa her tür uygulamaya (CRM/ERP/panel)
> hizmet eden genel bir çekirdek yeteneği mi?" İlki ise iptal edilir (bkz. T2.1 örneği).

---

## 0. NEREDE KALDIK? (her oturum başında güncelle)

- **Aktif faz:** TIER 1–2 tamamlandı. **TS**, **T3.1**, **T3.3**, **GC1**, **T3.5**,
  **GC2**, **T5.2a** (`cp:doctor`), **T3.6** (tamamı), **GC3** bitti.
  **T3.2 (multisite/org) İPTAL** (öncelik dışı).
- **Son oturum (5):** 2026-09-12 — **T3.4 Faz C: Studio içe aktarma ekranı** (`/admin/import`).
  Modül artık panelden kullanılabiliyor: kaynak listesi, seçenek formu, kuru çalıştırma
  raporu, ayrı "gerçekten aktar". CSV de kayıtlı bir migration oldu. Ayrıntı §4 (25).
- **Son oturum (4):** 2026-09-12 — **T3.4 Faz B2: medya + yorumlar.** Görseller asset'e
  giriyor, gövdedeki eski URL'ler (boyut türevleri dahil) yeniden yazılıyor, öne çıkan
  görsel ve yorumlar geliyor. **Satır 26: B → A.** Ayrıntı §4 (24).
- **Son oturum (3):** 2026-09-12 — **T3.4 Faz B1: Importer modülü + WordPress.**
  Kaynak sürücüleri çekirdeğe değil **modüle** konuldu (kullanıcı kararı): çekirdek CPalius
  yazar, modül yabancı sistemleri okur. Akışlı WXR okuyucu + 4 migration zinciri
  (yazar→kategori→etiket→yazı) çalışıyor. Çekirdeğe üç eksik eklendi: parametreli
  migration (`-o`), `MigrationLookup`, `User`/`Term` hedefleri. **Yeni `modules`
  test paketi** (phpunit + CI). Ayrıntı §4 (23).
- **Son oturum (2):** 2026-09-12 — **T3.4 Migrate API Faz A**: tipli boru hattı, idempotent
  map tablosu, dry-run varsayılan koşucu, `cp:migrate`, akışlı CSV kaynağı + Node hedefi.
  32 unit + 8 entegrasyon testi. Ayrıntı §4 (22). Satır 26 **D → B**.
- **Son oturum (1):** 2026-09-12 — **AACP konsolidasyonu + CSP nonce kapanışı** (2 commit).
  Hardening ayarları Güvenlik Merkezi'nden System Settings → Security'ye taşındı, merkez
  canlı ops ekranına indi; `importmap()` nonce'lu hâle getirildi; `QueryCounter` şema
  kataloğu sorgularını saymıyor. Ardından: `csp_nonce_attr()` yazılmıştı ama **hiçbir
  şablon çağırmıyordu** — 19 script etiketi nonce'suzdu, yani strict CSP modu paneli ve
  temayı sessizce öldürüyordu. Hepsi nonce'landı + `TemplateScriptNonceTest` ile kalıcı
  koruma altına alındı. **Commit'li, push yok.**
- **Sıradaki iş:** **T3.4 Faz B3** — XenForo / MyBB DB kaynak sürücüleri (satır 26'yı A+'a
  taşıyan tek şey; MyBB merge sistemi 16 forum yazılımının şemasını içeriyor, şema referansı
  olarak okunacak). Sonra Faz C (AACP sihirbazı) · T5.1 maker · T5.5 el kitabı.
- **Bekleyen migration:** yok. `Version20260912170000` (`cp_migration_map`) uygulandı.
- **Aktif modül sayısı: 9** — `Importer` eklendi ve etkinleştirildi.
- **Doğrulama durumu (2026-09-12):** PHPStan level 6 temiz (baseline **372**) ·
  php-cs-fixer temiz · **969 unit + 71 entegrasyon testi yeşil** ·
  `lint:twig` 241 dosya temiz · lint:container dev OK.
- **Bilinen ön koşullar:** DB için `C:\laragon\bin\php\php-8.4.14-nts-Win32-vs17-x64\php.exe`
  (bkz. memory `php-cli-environment`). Test DB `cpalius-cmf_test` (MySQL; `dbname_suffix`
  ile). `phpunit.xml.dist` kök dizinde.
  **Commit koruması:** `git config core.hooksPath .githooks` — boş dosya, %80'den fazla
  küçülen dosya ve sır içeren commit'leri engeller (bkz. §4, 2026-09-12 (19)).
- **Kalan GC borcu (bilinçli):** webhook → Messenger (hibrit kuyruk kararı); flat index
  Term; auth context-vary.
- **Kalan teknik borç:** migration'lar MySQL'e çivili (bkz. skor satırı 40 —
  **karar bekliyor:** "MySQL-only, bilinçli" mi, DBAL-taşınabilir mi).
  `SECURITY.md` / `LICENSE` iletişim adresi (`sys@rootali.net`) onay bekliyor.
- **Yeni karar konusu (2026-09-12):** strict modda `script-src` içinde `'strict-dynamic'`
  var, bu da ana kaynak ifadelerini geçersiz kılıyor → `security.csp_script_src` ayarına
  eklenen host'lar **strict modda hiçbir işe yaramıyor**, operatör "ekledim ama olmadı"
  durumunda kalıyor. Seçenekler: (a) ekstra host varsa `'strict-dynamic'`'i düşür,
  (b) ayarı strict modda arayüzde devre dışı bırakıp gerekçesini yaz. Sessiz bırakılmadı,
  bilinçli olarak sıraya alındı.

---

## 1. MEVCUT DURUM — CPalius bugün ne yapıyor?

Symfony 7.4 mikro-çekirdek + "işletim sistemi gibi" izolasyon. **Dayanıklılık ve
güvenlik-varsayılan** ekseninde dört rakibi de geçmiş durumda.

### Şu an olgun olan çekirdek sistemler
- **Çökmeyen çekirdek** — 4 savunma hattı: statik `active_modules.php`, `Kernel::boot`
  try/catch modül izolasyonu, aktivasyonda dry-run lint, `SafeModuleRouteLoader`.
- **Güvenlik-varsayılan (A++)** — CBAC (`CapabilityRegistry`, unknown=deny), YAML rol config,
  `CPaliusVoter`, `QueryScopeApplier` (voter→SQL, `.own`/`.any`), `TenantFilter` SQLFilter
  + otomatik `tenant_id` damgalama, dev N+1 guard (DBAL middleware), `SsrfGuard`,
  `RichTextSanitizer`, güvenlik telemetrisi + `ThreatAnalyzer`. **TS katmanı:** nonce'lu
  4 modlu CSP + HSTS + CSP rapor ucu, engelleyen WAF, CIDR/süreli IP ban + izin listesi,
  `FloodService`, authenticator seviyesinde kaba kuvvet savunması, HIBP'li parola
  politikası + parola geçmişi, çekirdekte TOTP 2FA + kurtarma kodları, oturum
  sertleştirme, şifreli ayar sırları, `cp:security:audit` duruş puanı ve AACP
  `/aacp/security` Güvenlik Merkezi.
- **Hibrit veri modeli** — `Node`: sıcak kolonlar + `data` JSON + `NodeFieldIndex` flat
  index (Law 6.3). EAV ve postmeta tuzaklarından kaçınılmış.
- **Platform servisleri** — REST gateway (`#[CpApi]` + API key + idempotency + rate limit),
  dual-lane Hook (flat-file + `#[CpHook]`), birleşik Cron (DB + `#[CpCronJob]` + flat-file,
  izole subprocess, whitelist), async queue (`AsyncJob` tablosu + `QueueWorker`),
  Webhook (in/out, HMAC), Settings (`#[CpSetting]`, lazy `SettingsRegistry`, scope),
  Audit log (`#[Auditable]`), Backup (PHP-içi DB dump + dosya ZIP), Origin HTML cache,
  Config Management / CMI (`cp:config export|import`, provider: settings + field + roles).
- **İçerik altyapısı** — Field API Faz A (tip/widget/formatter/definition/reference batch),
  Revision engine (`NodeRevision` + `NodeSnapshot`), Content Moderation
  (`moderation_state`, `status`'tan ayrı), Workflow engine (özel state machine, YAML).
- **`#[CpResource]` auto-admin** — otomatik CRUD + form + capability + audit + workflow
  butonları (Law 4.1/4.2). Henüz isimli bir `#[CpResource]` yok — saf makine.
- **Diğer** — Menu (WP tarzı drag-drop), `#[CpAdminMenu]`, Studio dashboard katkıları,
  Portal/anasayfa blok sistemi, Plugin katmanı (`PluginInterface` + toggle), Tema sistemi
  (decoupled, `theme.json`, çekirdek asset derlemez), `UrlAlias`, Pagination,
  `CpMailerService`, `GlobalSearchService` (tagged provider toplayıcı — sığ),
  Localization (içerik çevirisi `translation_group_id`, UI ICU YAML, `TranslationManager`).
- **Modüller (referans):** Blog, Forum, Media, Menu, Pages, Roadmap, Seo, Widget.
- **Test:** ~346 test, yeşil. 49 migration.

---

## 2. KIYAS SKOR KARTI — CPalius vs Drupal / TYPO3 / WordPress / ProcessWire

> **BAKIM KURALI:** Her tier adımı bittiğinde bu bölüm güncellenir. Değişen satırın
> CPalius puanı yükseltilir, "CPalius bugün" notu yeni gerçeğe göre yazılır, gerekiyorsa
> "en iyi kim" değerlendirmesi gözden geçirilir. Küçük bir geliştirme bile buraya yansır.
> Son güncelleme: **2026-09-12 (strict_types + stil sweep sonrası).**
>
> **HEDEF KRİTERİ (2026-09-11'den itibaren):** Her satırda amaç sadece rakiplere yetişmek
> (`A`) değil, **o satırın `A+`ı olmak.** Bir tier adımını tasarlarken "Drupal/ProcessWire/
> TYPO3/WP bunu nasıl yapıyor, hangi zaafı var, CPalius aynı işi nasıl daha temiz/güvenli/
> performanslı/tutarlı yapar" sorusu açıkça cevaplanır ve o cevap "alan alan" notuna yazılır.
> Bir adım biterken satır hâlâ `A+` değilse, neyin eksik olduğu ve hangi sonraki adımın onu
> tamamlayacağı **açıkça** yazılır (bkz. T1.4 örneği).

### Ölçek
`D` yok · `C` ilkel/parça · `B` kısmi · `A` olgun · `A+` sınıfının en iyisi
Sütunlar: **CP**=CPalius · **DR**=Drupal 10/11 · **T3**=TYPO3 v13 · **WP**=WordPress 6.x · **PW**=ProcessWire 3.x

### Skor tablosu

| # | Yetenek alanı | CP | DR | T3 | WP | PW | Yol haritası |
|---|---|:--:|:--:|:--:|:--:|:--:|---|
| 1 | Çökmeyen çekirdek / modül izolasyonu | A+ | B | B | C | B | — (olgun) |
| 2 | Güvenlik-varsayılan (CBAC, tenant, N+1 guard, XSS) | **A++** | A | A | C | A | TS ✅ — bkz. alan notu: çevre + kimlik + denetim üç katmanı da çekirdekte, dördünde de contrib. **2026-09-12:** strict CSP artık gerçekten kullanılabilir — 19 script etiketi nonce'landı, `TemplateScriptNonceTest` regresyonu statik olarak engelliyor. Rakip zaafı: dördünde de nonce'lu CSP ya contrib eklenti işi (WP/Drupal) ya da tema yazarının elle disiplinine bırakılmış; hiçbiri "nonce'suz script etiketi" durumunu makineyle denetlemiyor |
| 3 | Hibrit veri modeli + flat index | A | B | B | C | A | — |
| 4 | **Entity API** (tek fieldable/revision/i18n/access sözleşmesi) | B | A+ | B | C | A | T1.1 ✅ (Node+User fieldable) · revision/i18n genelleme kaldı |
| 5 | Field API (tip + widget + formatter + ayar) | B | A+ | A | C | A | T6 (Money/Link/Address/nested + validation UI) |
| 6 | Sorgu API (EntityQuery / Selector) | B | A | B | B | A+ | T1.2 ✅ (fluent + OR + accessCheck→SQL + flat index; Node-only) · genelleme T1.3 |
| 7 | Satır-bazlı erişim (access grants + query alter) | A+ | A+ | B | C | B | T1.4 ✅ tamamlandı — bkz. alan notu: Drupal'ı 4 eksenden geçiyor, ekosistem/UI'da eşit |
| 8 | Taksonomi (Vocabulary + Term, fieldable) | **A++** | A+ | A | A | A | GC1+GC2 ✅ — Term Field API + per-vocab yetki + Category/Tag→`blog_category`/`blog_tag` migrasyonu |
| 9 | Revizyon + moderasyon + workflow | B | A+ | A | B | C | T1.1 kuyruğu + (staging = uzak hedef) |
| 10 | Tipli entity yaşam döngüsü olayları | A+ | A | B | C | A | T1.5 ✅ tamamlandı — bkz. satır 23, WP'nin izolasyon zaafını çözdü |
| ~~11~~ | ~~Views / kodsuz listeleme (site-builder)~~ | — | — | — | — | — | **KALDIRILDI** (2026-09-11) — web-sitesi merkezli, çekirdeğin kapsamı dışı. 11 numarası bilinçli boş, diğer maddeler kaymadı |
| 12 | Görüntüleme modları (display modes) | A | A+ | A | A | C | T2.2 ✅ — API+tema TEK config'ten tutarlı (Drupal'da yok); tema suggestion cascade bilinçli eklenmedi (site-builder sınırına yakın) |
| 13 | Cache API (tag/context) + reverse-proxy purge | **A++** | A+ | A | C | B | GC1 ✅ — `config:*` tag invalidation + locale/anon context-vary. Kalan: auth vary, modül list tag migrasyonu, ESI |
| 14 | Origin / tam-sayfa HTML cache (çekirdekte) | A | A | A | D | B | — (CP: Redis'siz, shared hosting'de çalışır — ayırt edici) |
| 15 | Text formats + filtre pipeline (rol-bazlı) | A+ | A+ | B | B | B | T2.5 ✅ — kayıt+çıktı çift faz, deny-list override edilemez, token/markdown çekirdekte |
| 16 | Token + desen-bazlı path alias | A+ | A+ | A | B | B | T2.4 ✅ — hook fan-out yok, eski alias 404 olmaz, mail+SEO tüketici |
| 17 | Config management (kod olarak config sync) | A | A+ | B | D | B | T5.4 (kalan provider'lar + UI + split) |
| 18 | Çoklu dil — içerik + arayüz | A | A+ | A+ | C | A | — (CP: composite constraint + ICU, çekirdekte) |
| 19 | Medya boru hattı + responsive image + oEmbed | B | A | A | A | A | T4.3 |
| 20 | Async kuyruk + bildirim primitifi | **A++** | B | B | B | C | GC1+GC2 ✅ — Forum → `cp_notifications`; `forum_notifications` drop. Kalan: webhook→Messenger (bilinçli hibrit) |
| 21 | Multisite / domain / organizasyon | C | A | A+ | A | B | **T3.2 İPTAL** (öncelik dışı, 2026-09-12) |
| 22 | REST / JSON:API / headless auth | B | A+ | B | A | B | T4.1 + T4.2 |
| 23 | Hook / eklenti ergonomisi | B | A | B | A+ | A | T1.5 |
| 24 | Cron / zamanlanmış görevler | A | A | A | C | B | — (CP: birleşik motor + izole subprocess + UI) |
| 25 | Modül paketleme + lifecycle + tek-tık dağıtım | **A** | A | A | A+ | B | T3.6 ✅ `cp:update` sıralı+devam ettirilebilir; kalan: paket deposu / tek-tık kurulum |
| 26 | Migrate / CMS-ten CMS'e veri taşıma | **A** | A+ | B | B | C | T3.4 Faz A ✅ (motor + CSV + `cp:migrate`) · Faz B1 ✅ (**Importer modülü** + WXR: yazar/kategori/etiket/yazı) · **Faz B2 ✅ (medya + yorumlar)**. WordPress yolu artık gerçekten tam: görseller asset'e giriyor, **gövdedeki `-300x200` boyut türevleri dahil** yeniden yazılıyor (eski alan adı markup'ta kalmıyor), öne çıkan görsel `_thumbnail_id`'den çözülüyor, yorumlar thread'iyle geliyor. Drupal'ın dört zaafına karşı tasarlandı: dry-run varsayılan, transform tipli PHP (YAML eklenti id'si yok), koşucu çekirdekte, rollback map'e göre kesin; WXR **akışlı** (WP'nin kendi importer'ı tüm dosyayı belleğe alır). Faz C ✅ (Studio ekranı `/admin/import` — kaynak listesi, seçenek formu, kuru çalıştırma raporu, ayrı "gerçekten aktar"). **A+ için kalan tek şey: ikinci bir kaynak ailesi** (XenForo/MyBB forum) |
| 27 | Admin UI + kurtarma konsolu | **A+** | A | A | A | A | T3.5 ✅ — `/aacp/logs` watchdog + mail resend; `/aacp/recovery` DB'siz (ayırt edici) |
| 28 | Güvenlik telemetri + IP ban (çekirdekte) | A | C | C | C | C | — (çoğu rakipte contrib) |
| 29 | Yedekleme (çekirdekte) | A | C | C | C | C | — (çoğu rakipte contrib) |
| 30 | DX: maker + doctor + test kit + geliştirici dokümanı | **A** | A | B | B | B | T5.2 ✅ `cp:doctor` (10 kontrol) + `cp:debug` (6 konu) + `IntegrationTestCase`/`IntegrationSchema`; kalan: maker (T5.1), el kitabı (T5.5) |

### Ek eksenler (31–40) — "ürün olma" ekseni

> **NEDEN SONRADAN EKLENDİ (2026-09-12):** İlk 30 satır *yetenek* kıyaslıyor ve
> orada birçok satırda öndeyiz. Ama bir framework'ün "bunu seçelim mi" kararı
> büyük ölçüde burada veriliyor — ve dört rakip de tam olarak bu eksenlerde
> kazanıyordu. Bu blok eklenene kadar tablo, projenin en büyük açığını hiç
> göstermiyordu: satır 33 ve 34 `D` iken satır 2'yi `A++`'tan öteye taşımak
> hiçbir kullanıcı kazandırmaz.

| # | Yetenek alanı | CP | DR | T3 | WP | PW | Yol haritası |
|---|---|:--:|:--:|:--:|:--:|:--:|---|
| 31 | Sürüm politikası + BC garantisi + LTS | D | A+ | A+ | A++ | A | `cp:update` altyapısı hazır (T3.6); semver + BC belgesi + update-hook sözleşmesi yazılmalı |
| 32 | Kurulum deneyimi ("5 dakika kuralı") | **B** | B | C | A++ | A | `.env.example` + README geri geldi; kalan: tek komutluk kurulum sihirbazı |
| 33 | Dokümantasyon | **C** | A+ | A+ | A+ | A+ | README (EN+TR) + SECURITY.md var; geliştirici el kitabı yok → T5.5 |
| 34 | CI / otomatik kalite kapısı | **A** | A+ | A | B | B | ✅ GitHub Actions: lint ×3 + çekirdek izolasyon + PHPStan L6 + stil + `cp:doctor` + iki test paketi |
| 35 | Paket ekosistemi + dağıtım kanalı | D | A+ | A | A++ | B | Modül deposu/registry yok — satır 25'in kalan yarısı |
| 36 | Güvenlik açığı bildirim süreci + CVE | **B** | A++ | A+ | A | B | SECURITY.md + kapsam + SLA yazıldı; CVE/advisory süreci ve gerçek adres onayı kaldı |
| 37 | Performans kanıtı (benchmark) | D | B | B | C | A | İddia var, ölçüm yok — benchmark paketi yazılmalı |
| 38 | Erişilebilirlik (WCAG) — admin UI | ? | A+ | A | A | C | Hiç ölçülmedi; Drupal'ın a11y gate'i çekirdek politika |
| 39 | Gözlemlenebilirlik (log / metrik / health) | **A** | A | A | C | B | T3.5 ✅ `Core/Logging` watchdog + `/aacp/logs` + mail log; T5.2a ✅ `cp:doctor` |
| 40 | DB taşınabilirliği | C | A+ | A | C | C | 60 migration'ın hepsi ham MySQL DDL (`AUTO_INCREMENT`, `ENGINE=InnoDB`), platform koşulu **sıfır**. ORM katmanı taşınabilir, kurulum değil. **Karar bekliyor:** "MySQL-only, bilinçli" diye yazılacak mı, yoksa DBAL-taşınabilir migration disiplinine mi geçilecek |


### Alan alan — en iyi kim, CPalius nerede

- **4 Entity API** — *En iyi: Drupal* (her entity tipi tek API ile fieldable+revisionable+
  translatable+access). *ProcessWire* felsefe olarak da güçlü (her şey Page). *CPalius (T1.1
  sonrası):* `#[CpEntityType]` + `FieldableInterface` çekirdekte; Node **ve** User fieldable,
  Field API entity-agnostik. Eksik: revizyon/çeviri/moderasyon hâlâ Node'a özel → sözleşmeler
  2. gerçek tüketici çıkınca gelecek. **C → B.**
- **6 Sorgu API** — *En iyi: ProcessWire* (`$pages->find("template=x, price>100, sort=-created")`
  selector motoru onun taç mücevheri). Drupal `entityQuery`+Views query katmanı güçlü.
  *CPalius (T1.2 sonrası):* `CpEntityQuery` — akıcı `where`/`whereField` (flat index)/`orX`
  grup/`sort`/`range` + `accessCheck()` `.own`/`.any`'yi SQL WHERE'e çeviriyor (bu satır
  erişim entegrasyonu ham Drupal `entityQuery`'den ileride). Selector string aynı motora
  gidiyor. **Eksik:** Node-only (Resource/Term T1.3), ilişki/join gezinme yok, alan-bazlı sort
  yok, aggregation yok. **C → B.**
- **7 Satır-bazlı erişim** — *Önceki en iyi: Drupal* (`node_access` tablosu + realm/gid +
  query alter). Drupal'ın bilinen 4 zaafı: (1) realm/gid, normal yetki sisteminden **ayrı**
  ikinci bir zihinsel model — kafa karıştırıcılığıyla ünlü; (2) mantık değişince tüm
  node'lar için pahalı bir **rebuild** adımı gerekir, unutulursa sessizce yanlış sonuç verir;
  (3) çekirdekte yalnız Node — diğer entity tipleri ayrı contrib çözüm ister; (4) hook bir şeyi
  granted ederken yazım hatasını/var olmayan izni durduran bir doğrulama yok.
  *CPalius (T1.4 sonrası) `EntityAccessManager` + `cp_entity_access_grants`:* aynı 4 zaafı
  çözüyor — (1) **tek** capability isim uzayı (`node.post.view` gibi, CapabilityRegistry/
  RoleConfigManager ile aynı), ayrı bir realm/gid kavramı yok; (2) **rebuild yok** — her grant
  yazıldığı an bağımsız olarak doğru, artımlı; (3) `entity_type` string ile **her**
  `#[CpEntityType]` (Node, Term, ileride Resource) — Node'a özel değil; (4) bilinmeyen
  capability **reddedilir** (fail-safe, `.own`/`.any` ailesinden biri kayıtlı değilse grant
  oluşmaz). Sorgu tarafı `QueryScopeApplier`'a tek `EXISTS` alt-sorgusu olarak eklendi — Law
  6.2 ile aynı disiplin, per-row voter yok, N+1 yok. Denetim: `granted_by` + `created_at`
  çekirdekte (Drupal'da hiç yok). **Dürüst eksik:** tarayıcıdan "bu kaydı şu kullanıcıyla
  paylaş" admin UI'ı yok (Drupal çekirdeğinde de yok — contrib'de). Motor düzeyinde **C → A+.**
- **12 Görüntüleme modları** — *En iyi: Drupal* (Manage Display, 15+ yıllık olgunluk, sınırsız
  özel view mode). Zaafı: **tema-only** — JSON:API varsayılan çıktısı display config'i
  görmezden gelir, "teaser" sadece temada var, API'de yeniden yazman gerekir. *CPalius
  (T2.2 sonrası):* `EntityDisplayRegistry` TEK config; `FieldRenderer::renderEntity()`
  (tema) VE `FieldValueSerializer::serialize()` (API) AYNI görünürlük/sıra/etiket
  ayarını okuyor — "teaser" bir kere tanımlanır, ikisinde de tutarlı (test'te kanıtlı).
  AACP'de tüm view mode'lar tek matriste (Drupal: sekme sekme). **Kalan:** çalışma zamanında
  özel view mode oluşturma UI'ı yok (kod/YAML ile), formatter view mode başına override
  edilemiyor, tema suggestion cascade yok (bilinçli — site-builder sınırına yakın).
  **C → A** (A+ değil — Drupal'ın 15 yıllık genişliği hâlâ önde, ama API/tema tutarlılığında
  CPalius zaten Drupal'ın önünde).
- **8 Taksonomi** — *En iyi: Drupal* (fieldable vocabulary + çoklu üst term + granüler
  yetki). *CPalius (T1.3 sonrası):* `Vocabulary` + `Term` uçtan uca — fieldable (bundle =
  vocab machine_name), hiyerarşik (tek üst), translatable, `EntityReference` ile `term:{vid}`
  hedefi, `/aacp/taxonomy` CRUD UI (hiyerarşi + çeviri sekmeleri + dil filtresi), yapı config
  export (`TaxonomyConfigProvider`, upsert-only — CASCADE içerik kaybını önler). **Kalan:**
  per-vocab yetki, çoklu üst term, term formunda özel alan girişi (desen hazır, uygulama
  kaldı), `Category`/`Tag` migrasyonu. **C → A.**
- **13 Cache API** — *En iyi: Drupal* (cache tags/contexts + BigPipe, 15+ yıllık render
  pipeline'ın her katmanına gömülü otomatik tag toplama). *CPalius (T2.3 sonrası):* `CacheTag`
  (`node:42`/`list:node:post`/`config:blog.settings` sözleşimi) + `CacheTagCollector` (bir
  controller "bu sayfa şuna bağlı" der) + `CacheTagIndex` (tag→path ters-indeksi, `cache.fragments`
  pool'unda — çekirdekte daha önce **hiç kullanılmayan** bir pool'un ilk gerçek tüketicisi).
  Drupal'ın rebuild-gerektirmeyen T1.4 felsefesi burada da tekrarlandı: entity kaydı →
  `EntityLifecycleListener`'ın zaten yaydığı `entity.any.post_*` olayı → `EntityCacheTagInvalidator`
  otomatik `purgeTags('node:42', 'list:node', 'list:node:post')` çağırır — hiçbir controller'ın
  elle `purgeAreas()` çağırmasına gerek kalmadan (eski mekanizma hâlâ duruyor, geriye uyumlu,
  ama artık zorunlu değil). `X-Cache-Tags` yanıtı reverse-proxy'lere (Varnish xkey/Fastly
  Surrogate-Key) aynı sözleşmeyi dışa açar. **Kalan:** context-vary matrisi yok (bugün rol/tema
  bazlı varyant yerine "cookie varsa hiç cache'leme" kaba kuralı kullanılıyor — bilinçli, T2.x
  kapsamı dışına ertelendi); `config:*` tag'leri henüz hiçbir `SettingsRegistry` yazımına
  bağlanmadı; sadece `PostFrontController` (Blog) uçtan uca migrate edildi, Forum/Pages/Menu/
  Roadmap hâlâ eski `purgeAreas()` ile çalışıyor (doğru ama kaba). Gerçek ESI/fragment render
  BİLİNÇLİ eklenmedi — CPalius'un "reverse-proxy'siz shared hosting'de çalışır" farkına aykırı
  düşerdi (bkz. satır 14). **B → A** (A+ değil — Drupal'ın context/BigPipe genişliği önde, ama
  rebuild-free otomatik invalidation felsefesi T1.4/T1.5 ile aynı çizgide).
- **16 Token + path alias** — *Önceki en iyi: Drupal* (Token + Pathauto, 15 yıl). Üç zaaf:
  (1) `hook_tokens()` her değişimde **her** modülü tarar; (2) çözülemeyen token sessizce
  boş string olur, kırık kalıp fark edilmez; (3) Pathauto başlık değişince eski alias 404
  verir — Redirect contrib ayrıca kurulmalı. *CPalius (T2.4):* doğrudan `supports()` dispatch,
  çözülemeyen token köşeli parantez olarak durur, her UrlAlias canlı slug'a 301 (eski kısa
  link ölmez). İlişkili özneler (`node` → yazarı `user`) bağlama enjekte edilir — Drupal
  zincir sözdizimi (`[node:author:mail]`) yerine aynı tip sistemi. Mail ve SEO tüketicileri
  HTML-escape'li replace kullanır. Varsayılan `/blog/[node:created:Y]/[node:title]` seed.
  **Kalan:** Term otomatik alias (kolon hazır), `[node:author:mail]` zinciri bilinçli yok.
  **C → A+.**
- **15 Text formats** — *Önceki en iyi: Drupal Filter API*. Zaaf: yalnızca **çıktıda**
  filtreler, DB bir XSS deposu; bir filtre kapanınca eski markup canlanır; CKEditor toolbar
  ile allowlist kayar (bilinen XSS sınıfı); Token/Markdown contrib. *CPalius (T2.5):* kayıtta
  allowlist + çıktıda dönüşüm; script/style/form/iframe-on-save **hiçbir** formatın açamadığı
  deny-list (Full HTML dahil); tek allowlist = biçim (widget WYSIWYG'yi biçime göre açar);
  `text_format.{id}.use` yazımda yeniden kontrol (yetkisi düşen full_html POST'u sessizce
  düşer); markdown önce HTML kaçırır (ham HTML yok); YouTube/Vimeo embed oEmbed ağına
  çıkmadan id'den sandbox iframe. **Kalan:** Forum/bio hâlâ `RichTextSanitizer` sarmalayıcısı
  (Twig `|text_format` hazır); Jodit toolbar etiket-etiket allowlist'e kilitli değil (aç/kapa).
  **C → A+.**
- **17 Config management** — *En iyi: Drupal* (referans). *CPalius:* CMI gerçekten yakın —
  export/import/diff + provider mimarisi. Kalan: workflow/menu/display provider'ları + AACP UI
  + env split. **A (büyüyor).**
- **18 i18n** — *En iyi: Drupal + TYPO3* (ikisi de günü birinde çok-dilli tasarlanmış).
  *CPalius:* `translation_group_id` + composite unique + ICU + `TranslationManager` — çekirdekte,
  güçlü. WP'nin çekirdeğinde yok (contrib). **A (CP burada rakiplerin çoğunun önünde).**
- **21 Multisite** — *En iyi: TYPO3* (native çok-site, site başına page tree). *CPalius:*
  sadece `tenant_id` satır filtresi; domain routing / per-site tema-ayar / Organization entity
  yok. **T3.2.**
- **23 Hook ergonomisi** — *En iyi: WordPress* (`add_action`/`add_filter` — en ergonomik eklenti
  sistemi). ProcessWire hookable metotlar. *CPalius:* dual-lane hook + plugin var ama tipsiz,
  entity olay kataloğu yok. **T1.5.**
- **26 Migrate** — *En iyi: Drupal* (Migrate API — CMS-ten CMS'e taşımanın referansı).
  *CPalius:* yok. "Kimse Drupal'ı tercih etmesin" için en somut kaldıraç. **T3.4.**
- **10 Tipli entity yaşam döngüsü olayları** — *Düzeltme notu:* ilk taramada bu satıra
  WordPress'e A+ verilmişti; bu yanlıştı — WP'nin `do_action('save_post', $id, $post,
  $update)` argümanları **tipsiz ve pozisyonel**, bu satırın tam karşılığı değil (o WP'nin
  gücü satır 23 "hook ergonomisi"nde, orada hâlâ A+). *En iyi (düzeltilmiş): Drupal* (modern
  `hook_entity_insert(EntityInterface $entity)` gerçekten tipli) — ama isolation yok, bir
  hook implementasyonu fırlatırsa istek çöker. *CPalius (T1.5 sonrası):* `EntityPreSaveEvent`/
  `PostInsertEvent`/… tipli sınıflar, **paralel bir event bus icat edilmeden** mevcut Hook
  motoruna bindirildi (`HookContext::get('event')`) — modül zaten bildiği `#[CpHook]`'la
  dinliyor. Asıl fark: bir listener'ın kendi bug'ı (beklenmeyen exception) mevcut Hook
  izolasyonuyla karantinaya alınır, kayıt işlemi **başarıyla tamamlanır** — WP/Drupal'da bu
  senaryo fatal error'dur. Sadece bilinçli `reject()` kaydı gerçekten durdurur (control-flow,
  exception değil). `FieldableInterface` sayesinde Node/User/Term hepsi tek listener'dan
  otomatik — Blog/Forum'un ayrı ayrı Doctrine listener'ları gibi değil. **C → A+.**

### CPalius bugün NEREDE ÖNDE

- **1 Çökmeyen çekirdek** (A+) — 4 savunma hattı. Bozuk modül Drupal'da beyaz ekran, CPalius'ta
  karantina + çalışan core.
- **2 Güvenlik-varsayılan** (A++) — tenant SQLFilter + dev N+1 guard + voter→SQL üçlüsünün
  üstüne TS katmanı: nonce'lu CSP, engelleyen WAF, CIDR/süreli IP ban, flood + kaba kuvvet
  savunması, HIBP parola politikası, çekirdekte TOTP 2FA, oturum sertleştirme, şifreli ayar
  sırları ve puanlı `cp:security:audit`. Drupal'da bunların her biri ayrı contrib
  (seckit + perimeter + flood_control + password_policy + tfa + key), WordPress'te ücretli
  eklenti. Hiçbirinde tek bir yönetim ekranı ve tek bir duruş puanı yok.
- **14 Origin cache + 27 Recovery console + 28 Telemetri + 29 Backup** — hepsi çekirdekte,
  Redis/SSH gerektirmeden, shared hosting'de. Rakiplerde bunlar contrib/plugin.
- **18 i18n** ve **24 Cron** — çekirdekte olgun; WP'nin zayıf noktaları.
- **7 Satır-bazlı erişim** (T1.4, A+) — Drupal'ın 4 bilinen zaafını (realm/gid ayrılığı,
  rebuild adımı, Node-only, doğrulamasız hook) motor seviyesinde çözdü.
- **10 Tipli entity yaşam döngüsü olayları** (T1.5, A+) — bir listener'ın kendi bug'ı Hook
  izolasyonuyla karantinaya alınır, kayıt yine başarılı olur; WP/Drupal'da fatal error.
- **13 Cache API** (T2.3, B → A) — T1.4/T1.5 ile aynı "rebuild yok" felsefesi: tag invalidation
  entity olaylarından otomatik tetiklenir, hiçbir controller'ın elle purge çağırmasına gerek yok.
- **16 Token + path alias** (T2.4, C → A+) — Drupal Token `hook_tokens()` her istekte her
  modülü tarar; CPalius tipi doğrudan sağlayıcıya dispatch eder. Pathauto eski alias'ı 404
  yapar (ayrı Redirect modülü şart); CPalius her UrlAlias'ı canlı slug'a 301'ler, sıfır ekstra
  kurulum. Çözülemeyen token sessizce boşalmaz — köşeli parantez durur.
- **15 Text formats** (T2.5, C → A+) — Drupal yalnızca çıktıda filtreler (DB bir XSS deposu;
  filtre kapanınca eski markup canlanır). CPalius kayıtta allowlist + çıktıda dönüşüm +
  hiçbir formatın override edemediği deny-list. Yetkisi düşen format yazımda reddedilir.

### CPalius bugün NEREDE GERİDE (öncelik sırasına yakın)

Entity API kuyruğu (revision/i18n genelleme, 4/9) → Sorgu API
genelleme (6, Node dışı) → Cache API context/config tag'leri + modül migrasyonu (13) →
Migrate (26) → Multisite (21) → DX (30) → REST/headless (22) → Medya boru hattı (19).

**TIER 1 ve TIER 2 KAPANDI.** Kalan boşluklar TIER 3+.

---

## 3. YOL HARİTASI

> **HER ADIM SONUNDA (kapanış checklist'i):**
> 1. `phpunit` tam paket yeşil · `lint:container` dev+prod · `lint:yaml` · `lint:twig` · `phpstan`
> 2. N+1 kontrolü (dev guard tetiklenmiyor)
> 3. Bu maddenin `[ ]` → `[x]` işareti + kanıt satırı
> 4. **§2 skor kartını güncelle** — değişen satırın CP puanı + "CPalius bugün" notu
> 5. §0 "NEREDE KALDIK" + §4 ilerleme günlüğü + memory `platform-contracts-work`

### TIER 1 — Çekirdek omurga  `[x]`  TAMAMLANDI (2026-09-11 — T1.1 - T1.5 hepsi bitti)

> "Her sistemin altından kalkar" iddiasının şartı. Diğer her şey bunun üstüne oturur.
> Manifesto'nun two-class modeli (Node vs Resource) korunur; sadece genişletilir:
> "Node ve Resource, `CpEntityType` sözleşmesinin iki hazır profilidir."

#### T1.1 — Birleşik Entity / Bundle API  `[x]`  (2026-09-11)
- [x] `CpEntityType` attribute + `EntityTypeRegistry` + `EntityTypeRegistrationPass` (compile-time scan, `#[CpFieldType]` deseni) — `cp-core/src/Core/Entity/`
- [x] `FieldableInterface` — `fieldableEntityTypeId()`, `fieldableBundle()`, `fieldableLocale()`, `getFieldableData()`, `setFieldableData()`
- [x] `FieldContext` entity-agnostik (`?Node $node` → `?FieldableInterface $entity`; geri uyum için `node(): ?Node` yardımcısı)
- [x] `FieldValueNormalizer` / `FieldValuePersister` / `FieldValidator` — `FieldableInterface`
- [x] `FieldRenderer` / `FieldValueSerializer` / `FieldRuntime` (Twig) — `FieldableInterface`
- [x] `FieldsFormType` / `FieldableFormBuilder` — zaten `string $bundle`/`$locale` alıyordu, dokunulmadı
- [x] `Node` → `#[CpEntityType(id:'node', bundleable, revisionable, translatable)]` + `FieldableInterface` — davranış değişmedi (346 test yeşil)
- [x] `User` → `#[CpEntityType(id:'user')]` + `FieldableInterface` (bundle='user', locale='und')
- [x] `EntityTypeRegistry` AACP'de görünür — `AACPFieldController::knownBundles()` tek-bundle'lı fieldable entity type'ları ekliyor
- [x] Çeviri: `entity.type.node` / `entity.type.user` (EN + TR)
- [x] Testler: 346 mevcut yeşil + `EntityTypeRegistryTest` (6) + `FieldableEntityContractTest` (3, User'a alan yazma + Law 5.3) + `EntityTypeRegistrationTest` (compile-time keşif). Toplam 355/yeşil.
- [x] `FieldsFormType` → `UserType` (`fieldableFormBuilder->add($builder,'user',...)`, `field_locale` opsiyonu) + `AACPUserController` (`persistUserFields()` helper, oluştur+düzenle akışına bağlı, GET'te `currentUserFieldValues()` ile ön-doldurma, ihlaller alt-forma) + `aacp/users/form.html.twig` "Özel alanlar" kartı + `aacp.users.form.section_custom_fields` çevirisi
- [x] Test: `UserFieldableFormType` (real container, `user` alanı seed → formda çıkıyor → submit → persist)
- [ ] **KALAN (ertelendi):** ön yüz hesap profil formu (`AccountProfileType`) — ayrı tema yüzeyi, Pages/Blog A5 gibi opsiyonel tüketici entegrasyonu
- [ ] **KALAN (ertelendi):** `RevisionableInterface` / `ModeratableInterface` — revizyon motoru gerçekten 2. bir entity'yi desteklemesi gerektiğinde (boş marker interface eklemek manifesto "gereksiz soyutlama yok" kuralına aykırı). `#[CpEntityType]` `revisionable`/`translatable` bayrakları şimdilik "reserved".
- **Kanıt:** `/aacp/fields` → `user` bundle → alan ekle → AACP kullanıcı düzenle formunda "Özel alanlar" kartı → kaydet → kalıcı. Test + lint yeşil. Pages/Blog dokunulmadı.

#### T1.2 — `CpEntityQuery` (akıcı, erişim-farkında sorgu)  `[x]`  (2026-09-11)
- [x] `CpEntityQuery` (`cp-core/src/Core/Entity/Query/`) — `where(col,op,val)` (whitelist kolon + `= != < <= > >= like in "not in"` + null→IS NULL), `whereField(name,op,val)` (flat index join, tip→value kolonu), `orX()/andX()` tek seviye alt-grup, `whereNull/whereNotNull`, `sort()` (whitelist), `range()`, `accessCheck(base, ownerField)`, terminal `getResult/getOneOrNull/ids/count/toQueryBuilder`
- [x] `accessCheck()` → `QueryScopeApplier` (`.own`/`.any` → SQL; kullanıcısız → `1=0`). T1.4 grant sistemi bu kancaya eklenecek.
- [x] `CpEntityQueryFactory` (service, public) — `forBundle()`, `forNode()`, `forEntityType()` (v1: sadece `node`), `fromSelector()` (PW-tarzı string → motor; `type=` bundle'ı belirler)
- [x] `NodeRepository::findNodesBySelector()` → `@deprecated`, `fromSelector()`'a yönlendiriyor (gerçek çağıranı yoktu — sadece whitepaper örneği)
- [~] Entity-agnostik: **v1 Node-only.** `forEntityType()` diğer id'leri reddediyor. Flat index + kolon çözümü genelleştirmesi **T1.3'e ertelendi** (2. gerçek tüketici = Term).
- [x] Dev N+1 guard uyumlu (tek sorgu; testler guard aktifken geçiyor)
- [x] Testler: `CpEntityQueryTest` (4) — kolon + flat-index filtre, OR grup + count + ids, accessCheck→boş, selector string. Tam paket **360/yeşil**.
- **Kanıt:** `$factory->forBundle('page')->where('status','=','published')->whereField('rank','>=',5)->sort('title','ASC')->getResult()` tek SQL; `fromSelector('type=page, rank>=5, sort=-title')` aynı motora gidiyor.

#### T1.3 — Vocabulary + Term (genel taksonomi)  `[x]`  (2026-09-11 — çekirdek + UI + config provider)
- [x] `Vocabulary` + `Term` entity'leri (`cp-core/src/Core/Taxonomy/Entity/`) — `Term` `#[CpEntityType(id:'taxonomy_term', bundleable, translatable)]` + `FieldableInterface` (bundle = vocabulary machine_name) + `TranslatableInterface`; hiyerarşi self parent/children; migration `Version20260911120000` (`cp_vocabularies` + `cp_terms`, composite unique'ler)
- [x] `VocabularyRegistry` (cached, `FieldDefinitionRegistry` deseni) + `Vocabulary/TermRepository` + `CpTaxonomy` doctrine mapping
- [x] `Term` referansı Field API `EntityReference`'a: `ReferenceTargetResolver` `term` + `term:{vid}` hedefleri (resolve/isValidTarget/exists/load/allowedTargets); yeni ctor arg `VocabularyRegistry`
- [x] `capabilities.yaml` += `taxonomy.manage` (tek yetki v1); çeviri `entity.type.taxonomy_term`
- [x] Testler: `TaxonomyTest` (2) — per-vocab fieldable + hiyerarşi + entity type keşfi + reference resolve/exists/load. `FieldTestTrait` güncellendi. Tam paket **362/yeşil**.
- [x] AACP UI `/aacp/taxonomy` — vocabulary listesi + CRUD formu (`AACPTaxonomyController`, raw-request/plain-Twig, `AACPFieldController`/`CategoryAdminController` deseni) + term ağacı (hiyerarşi, girinti, sil/düzenle) + term formu (parent seçici sadece `hierarchical` vocabulary'de, `TranslationGroupResolver` ile çeviri sekmeleri, `LocaleProvider` ile dil filtresi) + menü girişi (`#[CpAdminMenu]`, `aacp_tools` altında)
- [x] Config provider `TaxonomyConfigProvider` (`taxonomy.{machine_name}`) — **upsert-only** (FieldConfigProvider'ın aksine hiç silmiyor; silme CASCADE ile terimleri/içeriği götürür, o yüzden yalnız AACP'den ve yalnız boş vocabulary'de) + `VocabularySeeder` (modül installer'ları için, `FieldDefinitionSeeder` deseni)
- [x] Vocabulary silme, içinde terim varsa engellenir (`aacp.taxonomy.error.vocabulary_not_empty`) — CASCADE içerik kaybını AACP seviyesinde önler
- [x] Testler: `AacpTaxonomyControllerTest` (3) — vocabulary CRUD, hiyerarşi + guarded delete, config provider export/diff/upsert round-trip. Tam paket **365/yeşil**.
- [x] **GC1:** per-vocabulary capability (`taxonomy.{vid}.manage`) — runtime registrar + term OR-grant; vocab CRUD global `taxonomy.manage`
- [x] **GC1:** Term formuna Field API entegrasyonu (`FieldableFormBuilder` + `FieldValuePersister`; bundle = vocab machine_name)
- [x] **GC2:** Category/Tag → Vocabulary veri migrasyonu (`blog_category` / `blog_tag`; `Version20260912160000`; facade repo’lar Term döner)
- [ ] **KALAN (ertelendi):** flat index + `CpEntityQuery` Term desteği (Term'ler genelde kolon/parent/slug ile sorgulanır — özel alan sorgusu ihtiyaç olunca)
- **Kanıt:** `/aacp/taxonomy` → vocabulary oluştur → terim ağacı kur (hiyerarşi + çeviri) → `cp:config export` ile `taxonomy.{vid}.yaml` diske yazılır. Pages/Blog/Category hiç bozulmadı. "Kanallar" vocabulary'si oluştur → video node type'ına term reference alanı ekle → ata → sorgula.

#### T1.4 — Satır-bazlı erişim grant sistemi  `[x]`  (2026-09-11 — A+ hedefi: Drupal'ın 4 bilinen zaafını çözerek)
- [x] `EntityAccessGrant` (`cp-core/src/Core/Security/Entity/`, migration `Version20260911180000`, `cp_entity_access_grants`) — entity_type + entity_id + **capability** (Drupal'ın realm/gid'i yerine tek isim uzayı) + subject (user/role/any) + `granted_by`/`created_at` (denetim, Drupal'da yok)
- [x] `EntityAccessManager` — `grantToUser/Role/Anyone`, `revokeFrom*`, `revokeAllForEntity` (hard-delete çağıranın sorumluluğu, gerçek FK yok — polymorphic), `grantsFor`, `isGrantedForRecord` (tekil kayıt), `buildScopeCondition` (liste sorguları için EXISTS DQL parçası)
- [x] **Rebuild adımı yok** — Drupal'ın `node_access_rebuild()`'inin aksine her grant yazıldığı an bağımsız doğru
- [x] Bilinmeyen capability reddedilir (`.own`/`.any` ailesinden biri `CapabilityRegistry`'de kayıtlı değilse `grant()` `null` döner, satır oluşmaz) — fail-safe
- [x] `QueryScopeApplier::apply()` güncellendi — `.any` hâlâ hızlı yol; `.own` VE grant EXISTS'i `OR` ile birleşiyor; ne `.own` ne `.any` ne grant varsa sonuç boş. Yeni 5. parametre `$entityType='node'` (geriye uyumlu, mevcut 6 çağıran — Blog/Pages/Studio — dokunulmadan çalışmaya devam ediyor)
- [x] `CpEntityQuery::accessCheck()` otomatik olarak yeni `QueryScopeApplier`'dan geçiyor (değişiklik gerekmedi — zaten ona delege ediyordu)
- [x] Testler: `EntityAccessGrantTest` (4) — user grant sadece o kaydı açıyor, role grant o role sahip herkesi, anyone grant + bilinmeyen capability reddi, **regresyon:** mevcut `.own` davranışı grant sistemiyle yan yana bozulmadan çalışıyor. Tam paket **369/yeşil** (328 unit + 41 integration), Blog/Pages/Studio dashboard testleri dahil hiç kırılmadı.
- [ ] **KALAN (ertelendi):** tarayıcıdan grant yönetim UI'ı (Drupal çekirdeğinde de yok); `hook_entity_access` benzeri tipli olay → **T1.5**'e taşındı (aynı işi görür)
- **Kanıt:** `editor` rolünde `node.post.edit.own` olan bir kullanıcı kendi node'unu görür; `member` rolünde (`node.post.*` hiç yok) hiçbir post'u göremezken, o kullanıcıya tek bir post için `grantToUser('node', $id, 'node.post.view', $userId)` çağrılınca **sadece o post** listede çıkıyor — tek SQL, per-row voter yok.

#### T1.5 — Tipli entity yaşam döngüsü olayları  `[x]`  (2026-09-11 — A+ hedefi: WordPress'in hook zaaflarını çözerek)
- [x] `EntityEvent` ailesi (`cp-core/src/Core/Entity/Event/`): `EntityPreSaveEvent` (+ `isNew()`, `reject()`), `EntityPostInsertEvent`, `EntityPostUpdateEvent`, `EntityPreDeleteEvent` (+ `reject()`), `EntityPostDeleteEvent`, `EntityAccessEvent` (+ `allow()/deny()`) — hepsi tipli, WP'nin pozisyonel/tipsiz `do_action` argümanlarının tersi
- [x] **Mimari karar — paralel event bus yok:** olaylar mevcut Hook motoru üzerinden dağıtılıyor (`HookContext::get('event')`), yeni bir attribute/compiler pass icat edilmedi — modüller zaten bildiği `#[CpHook(hookPoint: 'entity.node.post_insert')]` ile dinliyor. Bu, WP'nin (bu satırın eski A+'ı) en büyük zaafını doğrudan çözüyor: **bir listener'ın fırlattığı hata WP'de tüm sayfayı çökertir (fatal error); CPalius'ta mevcut `HookManager` izolasyonu sayesinde sessizce karantinaya alınır, işlem (kayıt) başarıyla tamamlanır** — "Core Never Dies" artık entity olaylarına da genişledi.
- [x] `EntityLifecycleListener` — TEK, entity-agnostik Doctrine dinleyici (`FieldableInterface` kontrolü) → Node/User/Term hepsi otomatik, ekstra kod sıfır (T1.1'in ödemesi). Hook noktaları: `entity.{type}.{moment}` + joker `entity.any.{moment}`
- [x] Veto mekanizması: `reject()` = bilinçli iş kararı → `EntityLifecycleRejectedException` fırlatılır, Doctrine flush/persist gerçekten durur. Bir listener'ın kendi BUG'ı (fırlattığı beklenmeyen exception) ise → mevcut Hook izolasyonu yakalar, karantina loguna yazar, işlem durmaz. İkisi kasıtlı olarak farklı.
- [x] `EntityAccessEvent` → `CPaliusVoter`'a bağlandı; **T1.4 tutarlılık düzeltmesi de bu adımda yapıldı:** Voter artık `EntityAccessManager::isGrantedForRecord()`'u da danışıyor — T1.4'ün per-record grant'i artık sadece `CpEntityQuery` liste taramasında değil, `#[IsGranted]`/`denyAccessUnlessGranted()` tekil-kayıt kontrollerinde de çalışıyor (önceki oturumda atlanan gerçek bir tutarlılık boşluğuydu, şimdi kapandı). `supports()` de aynı "aile" kuralına güncellendi (bare capability, `.own`/`.any`'den biri kayıtlıysa tanınır).
- [x] Testler: `EntityLifecycleListenerTest` (6, unit, sahte dispatcher) — tipli dispatch, hook noktası isimlendirme, veto, entity-agnostik (fieldable olmayan görmezden gelinir). `EntityLifecycleHookIntegrationTest` (4, gerçek kernel + yeni `HookFixture` fixture modülü + gerçek `#[CpHook]` servisi) — post_insert gerçek flush'ta tetikleniyor, pre_save veto gerçekten kaydı durduruyor, **bug'lı bir listener karantinaya alınıyor ve kayıt yine de başarılı oluyor**, Access event Voter'ı role+grant'in ulaşamadığı yere taşıyor. Tam paket **379/yeşil** (334 unit + 45 integration).
- **Kanıt:** Bug'lı bir `#[CpHook(hookPoint:'entity.node.post_insert')]` dinleyicisi fırlatır → node yine de kaydedilir, `module_quarantine.log`'a yazılır (WP'de bu senaryo beyaz ekrandır). Bilinçli `reject()` ise kaydı gerçekten durdurur. `member` rolünde `node.post.*` hiç olmayan bir kullanıcı, Access event listener `allow()` çağırınca `isGranted('node.post.view', $node)` üzerinden erişim kazanıyor.

---

### TIER 2 — Entegratör gücü  `[x]`  TAMAMLANDI (2026-09-11 — T2.2–T2.5; T2.1 iptal)

#### T2.1 — ~~"Listing / SavedQuery" motoru (Views-lite)~~ **İPTAL** (2026-09-11, kullanıcı kararı)
> **İPTAL GEREKÇESİ:** CPalius sadece web sitesi değil, CRM/ERP/hosting paneli/dosya paylaşım
> gibi çok farklı proje tiplerinde kullanılacak bir **çekirdek**. "Site builder" (kodsuz
> liste/blok/feed kurma UI'ı) web-sitesi merkezli bir CMS özelliği — çekirdeğe gereksiz yük
> bindirir ve projenin "her türlü uygulamanın altından kalkan çekirdek" amacından saptırır.
> Bu madde ve karşılık gelen kıyas satırı ("Views / kodsuz listeleme") **kalıcı olarak
> kaldırıldı** — bkz. §2 skor tablosu (11 numarası artık boş, yeniden numaralandırma
> yapılmadı ki diğer madde referansları bozulmasın). `CpEntityQuery` (T1.2) hâlâ duruyor ve
> geçerli — o genel bir sorgu API'si, site-builder değil; onun üstüne UI kurmak iptal edildi.

#### T2.2 — View modes + display modes  `[x]`  (2026-09-11 — A+ hedefi: Drupal'ın "theme-only" zaafını çözerek)
- [x] `ViewModeRegistry` (`cp-core/src/Core/Display/`) — `CapabilityRegistry` deseni: core `view_modes.yaml` (`default`/`teaser`/`card`) + modül `Resources/config/view_modes.yaml`, `ViewModeRegistrationPass`. `default` her zaman var (registry garantisi)
- [x] `EntityDisplay` (bundle+view_mode+field → visible/weight/label_display) + migration `Version20260911200000` (`cp_entity_displays`) + `EntityDisplayRegistry` (cached, `FieldDefinitionRegistry` deseni) — yapılandırılmamış hücre = alanın kendi varsayılanı (sıfır-config = eski davranış, hiç fark yok)
- [x] **A+ farkı (Drupal'ın "Manage Display" zaafı — sadece tema, JSON:API görmezden gelir):** TEK config hem `FieldRenderer::renderEntity()` (Twig) HEM `FieldValueSerializer::serialize()` (API) tarafından okunuyor — "teaser" bir kere tanımlanır, temada da API'de de aynı alanlar çıkar. Testte kanıtlandı.
- [x] AACP UI: `/aacp/display/{bundle}` — Drupal'ın bir-view-mode-bir-sekme modelinin aksine **tek matris ekranında tüm view mode'lar** (satır=alan, sütun=view mode, hücre=visible+weight+label)
- [x] Config provider `EntityDisplayConfigProvider` (`display.{bundle}`) — authoritative (silinen override alanı kendi varsayılanına döner, içerik kaybı yok — Field API'nin `FieldConfigProvider`'ıyla aynı gerekçe)
- [x] Twig: `cp_entity_view(entity, viewMode)` fonksiyonu (`FieldExtension`/`FieldRuntime`'a eklendi)
- [x] Testler: `ViewModeRegistryTest` (3) + `EntityDisplayRegistryTest` (3, unit) + `EntityDisplayTest` (3, integration — render+API tutarlılığı, AACP kaydetme, config provider authoritative round-trip). Tam paket **346 unit** + entegrasyon büyüdü (bkz. günlük).
- [ ] **KALAN (bilinçli ertelendi — "site builder" hattına yaklaştığı için):** Twig şablon suggestion cascade (`entity--{type}--{bundle}--{viewmode}.html.twig` otomatik dosya çözümü). Tema geliştiricisi zaten `cp_field()`/`cp_fields()` ile kendi şablonunu elle yazabiliyor (Pages/Blog'un yaptığı gibi); otomatik dosya-adı tahmin makinesi web-sitesi temalaması için var olan bir mekanizma — CRM/ERP/panel senaryolarına hizmet etmiyor, bu yüzden çekirdeğe eklenmedi.
- [ ] **KALAN (ertelendi):** view mode başına formatter/ayar override'ı (v1'de sadece visible/weight/label değişiyor, formatter her yerde alanın kendi ayarını kullanıyor)
- **Kanıt:** `internal_notes` alanını `teaser` view mode'unda gizle → hem `renderEntity($node,'teaser')` HTML'inde hem `serialize($node,'teaser')` API çıktısında yok; `default`'ta ikisinde de var — tek config, iki tutarlı çıktı.

#### T2.3 — Cache tag / context primitifi  `[x]`  (2026-09-11 — B → A: T1.4/T1.5'in rebuild-free felsefesi cache'e taşındı)
- [x] `CacheTag` (`node:42`/`list:node:post`/`config:blog.settings` sözleşimi) + `CacheTagCollector` (request-scoped, bir controller "bu sayfa şuna bağlı" der, `setMaxAge()` ile per-response TTL override) — `cp-core/src/Core/OriginCache/`
- [x] Tag-farkında `OriginCachePurger::purgeTags()` — eski `purgeAreas()` olay listesi hâlâ duruyor (geriye uyumlu, 14 çağıran dokunulmadı) ama artık zorunlu değil
- [x] Response header: `X-Cache-Tags` (Varnish xkey / Fastly Surrogate-Key ile aynı fikir — CPalius kendi reverse-proxy'sini gerektirmez, önündeki biri varsa aynı sözleşmeyi devralabilir)
- [x] `cache.fragments` pool'u gerçekten kullanılıyor — ama fragment/ESI render için değil (bkz. KALAN), `CacheTagIndex`'in tag→path ters-indeksi için. **Yan keşif:** pool hiç kullanılmadığı için şimdiye kadar gizli kalmış bir config bug'ı ortaya çıktı — `when@dev`/`when@test` sadece `adapter`'ı değiştiriyordu, miras kalan `provider: '%env(MEMCACHED_DSN)%'` temizlenmediğinden ArrayAdapter'ın altında gerçek Memcached'e bağlanmaya çalışıyordu (`cache.yaml`, `provider: ~` ile düzeltildi)
- [x] Entity kaydı → ilgili tag'ler otomatik invalidate: `EntityCacheTagInvalidator` (`#[CpHook]`, `entity.any.post_{insert,update,delete}`) — T1.5'in event'inden `{type}:{id}` + `list:{type}` + `list:{type}:{bundle}` tag'lerini çıkarıp `purgeTags()` çağırır, hiç controller kodu gerekmez
- [x] Somut migrasyon: `Modules\Blog\PostFrontController` uçtan uca bağlandı (`show()` → `addEntityTag('node', $id)`, `index()/category()/tag()/archiveMonth()` → `addListTag('node','post')`) — T2.3'ün kanıtı bu yolla çalışır
- [x] **GC1:** locale/anon context-vary (`OriginCacheVaryContext`, `_ctx/{locale}/anon/…`; auth BYPASS)
- [x] **GC1:** `config:*` tag'leri — `SettingsRegistry::clearCache(...$keys)` + touched-key drain → OriginCacheWriter
- [ ] **KALAN (ertelendi):** auth/role context vary; Forum/Pages/Menu/Roadmap list-tag migrasyonu (hâlâ `purgeAreas()`); gerçek ESI
- [ ] **KALAN (bilinçli eklenmedi):** gerçek ESI/fragment render (`render_esi()`) — CPalius'un "reverse-proxy gerektirmeden shared hosting'de çalışır" farkına (bkz. §2 satır 14) aykırı düşerdi; `cache.fragments` adı ESI'yi çağrıştırsa da bu bilinçli bir kapsam kararı
- **Kanıt:** `PostFrontController::show()` render ettiği node'u `CacheTagCollector`'a `node:{id}` olarak kaydeder; `EntityCacheTagInvalidator` unit testi (`EntityCacheTagInvalidatorTest`) node 42 için `post_update` event'i verildiğinde sadece `/blog/post-42` + `list:node`/`list:node:post` altında kayıtlı sayfaları düşürüp `/forum` gibi ilgisiz bir sayfaya dokunmadığını gerçek `OriginCacheStore`/`CacheTagIndex` üzerinde kanıtlıyor.

#### T2.4 — Token servisi + desen-bazlı path alias  `[x]`  (2026-09-11 — A+ hedefi: Drupal Token/Pathauto zaaflarını çözerek)
- [x] `TokenReplacer` — `[node:title]`, `[user:mail]`, `[site:name]`, `[term:name]` + tarih argümanı (`[node:created:Y]`); modül `Resources/config/tokens.yaml` katkısı (`TokenTypeRegistrationPass`)
- [x] Doğrudan `supports()` dispatch (Drupal `hook_tokens()` fan-out yok); çözülemeyen token köşeli parantez olarak durur (sessiz boş string yok)
- [x] `TokenContext::for($node)` yazarı `user` olarak enjekte eder — zincir sözdizimi olmadan `[user:mail]` bir node kalıbında çalışır
- [x] Pattern-bazlı otomatik alias: `PathAliasGenerator` mevcut `UrlAlias` katmanına yazar (Node::slug kanonik kalır); başlık değişince yeni alias eklenir, **eski alias 301'i kırmadan durur** (Drupal Pathauto+Redirect zaafı)
- [x] T1.5 `entity.node.post_insert/update` hook'u ile otomatik üretim (`PathAliasAutoGenerateListener`) — bug Hook izolasyonunda karantinaya alınır
- [x] AACP `/aacp/path-patterns` tek matris + kayıt-anında tip doğrulama + mevcut içerik için regenerate
- [x] Mail (`AccountRegistrationService`) + SEO (`BlogSeoProvider`) Token kullanır; HTML gövdelerinde escape
- [x] Config provider `path_pattern.{bundle}.yaml` + varsayılan seed: post `/blog/[node:created:Y]/[node:title]`, page `/[node:title]`
- [ ] **KALAN (ertelendi):** Term otomatik alias (entity_type kolonu hazır); Drupal-tarzı `[node:author:mail]` zinciri bilinçli yok (TokenContext yeterli)
- **Kanıt:** Yeni post → otomatik `blog/2026/baslik-slug` UrlAlias; doğrulama mailinde `[user:display_name]` ve `[site:name]` çözülür.

#### T2.5 — İsimli text format'lar + filtre pipeline  `[x]`  (2026-09-11 — A+ hedefi: Drupal Filter API'nin "yalnızca çıktı" zaafını çözerek)
- [x] `TextFormat` YAML kataloğu + DB override: `restricted` / `basic_html` / `full_html` / `markdown`
- [x] Format başına filtre zinciri: `html_restrict`, `markdown`, `token`, `auto_link`, `media_embed`, `nl2br` — kayıt (`storage`) ve çıktı (`output`) ayrı faz
- [x] Hardcoded deny-list (script/style/form/iframe-on-save/on*) hiçbir formatın, Full HTML'in bile override edemediği; çıktıda failsafe + media_embed en son (sandbox iframe)
- [x] Rol-bazlı erişim: `text_format.{id}.use` (editor hepsi, member yalnız `restricted`); yazımda yeniden kontrol — crafted `full_html` POST'u düşer
- [x] RichText field `{value, format}` saklar (eski düz string = `basic_html`); widget format seçici; `RichTextSanitizer` Forum/bio için duruyor, Twig `|text_format` yeni tüketiciler için
- [x] Config provider `text_format.{id}.yaml` + AACP `/aacp/text-formats`
- [ ] **KALAN (ertelendi):** Forum/user bio format seçiciye geçiş (Twig filtresi hazır); Jodit toolbar'ın etiket-etiket allowlist kilidi (v1: biçime göre aç/kapa); oEmbed ağ çağrısı T4.3
- **Kanıt:** Editör `full_html` seçebilir, member yalnız `restricted`; markdown formatında `**x**` HTML olur, `<script>` kayıttan önce kaçar.

---

### TS — Güvenlik sertleştirme katmanı  `[x]`  (2026-09-12 — skor 2 satırı **A → A++**)

Hedef A+ değil **A++**'tı: rakiplerde contrib/eklenti olan her şeyin çekirdekte,
varsayılan açık ve tek ekrandan yönetilebilir olması. Katman üçe ayrılıyor —
**çevre**, **kimlik**, **denetim**. Tamamı `#[CpSetting]` ile 47 ayar olarak
`security.*` gruplarında; hiçbiri kod değişikliği istemiyor.

**Tasarım ilkesi — sertleştirme bozulur, 500 vermez.** Perimeter kodunun tamamı
try/catch içinde. Bir güvenlik katmanının kendi hatası yüzünden siteyi düşürmesi,
korumaya çalıştığı saldırıdan daha büyük bir kesinti olurdu.

**Çevre (perimeter)**
- [x] `SecurityHeaderPolicy` + `SecurityHeadersSubscriber` + `CspNonceProvider` — dört
      modlu CSP: `off` / `report` / `balanced` / `strict`. Aç-kapa değil dört mod,
      çünkü temalanabilir bir CMS sahibi olmadığı şablonlara sessizce nonce politikası
      dayatamaz. Varsayılan `report`: operatör zorunlu kılmadan önce kanıtı görüyor.
      `balanced` modda **nonce üretilmiyor** — üretilse tarayıcı `'unsafe-inline'`'ı
      yok sayar ve tema kırılırdı. HSTS yalnız `isSecure()` iken. Var olan başlığın
      üstüne asla yazmıyor; `/_wdt`, `/_profiler` ve indirme yanıtları atlanıyor.
- [x] `CspReportController` (`/_cp/csp-report`) — flood limitli (fail-closed), 8 KB
      gövde tavanı, yalnız 6 beyaz listeli alan 300 karaktere kırpılarak telemetriye.
- [x] `RequestGuardSubscriber` — site kapısı. WAF imza analizi `TERMINATE`'ten
      `REQUEST`'e taşındı: eskiden yalnızca tespit edebiliyordu, artık **reddedebiliyor**.
      Verdict Request üzerinde saklanıyor, `TelemetrySubscriber` ikinci kez taramıyor.
      Blokta hep aynı kısa 403 dönüyor — sonda hangi kontrolün tetiklendiğini öğrenemiyor.
- [x] **`/aacp` ban muafiyeti kaldırıldı** — banlı bir host admin login'ini dövmeye devam
      edebiliyordu. `/aacp/recovery` muaf kalıyor: belgelenmiş geri dönüş yolu o.
- [x] `IpBanService` v2 + `IpMatcher` — CIDR (IPv4/IPv6, `inet_pton` bayt karşılaştırma),
      süreli ban, gerekçe, kaynak (manual/auto), vuruş sayacı; tek sorguda eşleşme.
      **İzin listesi her banı geçersiz kılar** ve allowlist'teki bir adres banlanamıyor —
      sertleştirmenin operatörü kendi sitesinden kilitlemesi mümkün değil. Otomatik ban
      manuel/kalıcı bir banı asla kısaltmıyor.
- [x] `framework.yaml` → `trusted_proxies` + `trusted_headers`. **`trusted_hosts`
      bilinçli olarak framework'e verilmedi:** boot sırasında uygulanıyor ve çözülmeyen
      bir env değişkeni `setTrustedHosts([null])` üretip her host'u reddederek siteyi
      geri dönüşsüz düşürüyor. Host izin listesi bunun yerine `security.trusted_hosts`
      ayarından `RequestGuardSubscriber::isHostAllowed()` içinde çalışıyor.

**Kimlik (credentials)**
- [x] `FloodService` — T3.3'ün tamamı (yukarı bkz.).
- [x] `LoginDefenseService` + `LoginDefenseSubscriber` — POST yolunda değil,
      **authenticator event** seviyesinde (`CheckPassportEvent`, öncelik 2048): bugünkü
      ve gelecekteki her authenticator'ı kapsıyor ve **parola hash'lenmeden önce**
      kesiyor. Kimlik `UserBadge::getUserIdentifier()`'dan okunuyor, kullanıcı
      **çözülmeden** — hem kilitli saldırgan için boşa DB sorgusu yok, hem de yanıt
      süresinden kullanıcı adı doğrulanamıyor. İki bağımsız sayaç: hesap başına
      (password spraying) ve IP başına (credential stuffing). Tekrarlanan kilitler 24
      saatlik pencerede sayılıp süreli otomatik IP banına yükseliyor. CAPTCHA, IP
      hakkının yarısı yandığında kendiliğinden devreye giriyor.
- [x] `PasswordPolicy` + `BreachChecker` + `PasswordHistory` — uzunluk, karakter sınıfı,
      gömülü deny-list, kimlik benzerliği, **HIBP k-anonymity** (SHA-1'in yalnız ilk 5
      karakteri çıkıyor, `Add-Padding`, 24 saat cache) ve tekrar kullanım geçmişi.
      Sızıntı servisi yanıt vermezse varsayılan fail-open — ağ arızası parola
      değiştirmeyi kilitlememeli; isteyen `password_breach_fail_closed` ile tersine
      çeviriyor.
- [x] `PasswordChanger` — `User::$password`'a yazan **tek yer**. Beş çağrı noktası
      (kayıt, profil, AACP kullanıcı formu × 2, `cp:create-admin`) buradan geçiyor; hash,
      `password_changed_at` damgası ve geçmiş kaydı birlikte yapılıyor, yani gelecekteki
      bir çağrı politikayı sessizce atlayamıyor.
- [x] `TotpGenerator` + `TwoFactorService` + `TwoFactorGuardSubscriber` + 3 şablon —
      RFC 6238 TOTP ve RFC 4648 base32 **çekirdekte yazıldı**, bağımlılık eklenmedi.
      Durum `User::$data` içinde (hibrit model; asla cross-query edilmiyor), secret
      `SecretBox` ile mühürlü. Kullanılan counter saklanıyor: aynı kod 30 saniye içinde
      **ikinci kez kabul edilmiyor**. 8 kurtarma kodu HMAC'li — yavaş hash değil, çünkü
      hiçbir wordlist'te olmayan 40 bit rastgelelik. Yetkili hesaplara zorunlu kılma
      (`system.aacp.access`) ve kapatma yasağı.
- [x] `SessionRegistry` + `SessionGuardSubscriber` — idle/absolute ömür, tarayıcıya
      bağlama, uzaktan iptal. Session id **hash'lenerek** saklanıyor; tabloda okunabilir
      bir session id durması oturum çalmanın kestirme yolu olurdu. IP bağlama ayrı ve
      varsayılan kapalı: mobil ağda IP meşru olarak değişiyor.

**Denetim (governance)**
- [x] `SettingSecretCodec` — `password` tipli ayarlar `cpenc:v1:` önekiyle şifreli
      saklanıyor. Açık önek sayesinde mevcut düz metin "bozuk şifreli metin" sanılmıyor,
      legacy olarak tanınıyor; denetim kalan düz metin sır sayısını bulgu olarak veriyor.
      Çözme başarısızsa `''` dönüyor — özellik "yapılandırılmamış" diyor, şifreli metni
      dışarı sızdırmıyor.
- [x] `SecurityAuditor` + `SecurityFinding` + `cp:security:audit` — kayıtlı ayarı değil
      **çalışan yapılandırmayı** denetleyen 18 kontrol, 100 üzerinden duruş puanı,
      `--fail-on` ile CI'ı kesebiliyor, `--json` ile makineye okunabiliyor. İstek
      gerektiren kontroller (HTTPS, proxy tutarlılığı) konsolda **atlanıyor** —
      kanıtlayamadığı bir "geçti" raporlamak hiç raporlamamaktan kötü olurdu.
- [x] `SecurityMaintenanceTask` (`#[CpCronJob]` 03:15) — süresi dolan banları ve ölü
      oturum kayıtlarını temizliyor.
- [x] **AACP `/aacp/security` Güvenlik Merkezi** — duruş puanı + bulgu tablosu, altı
      grupta 47 ayar, kendi 2FA kartı, canlı IP ban tablosu (ekle/kaldır) ve etkin
      oturum tablosu (iptal). `SystemSettingsService::TAB_SECURITY_CENTER` **sanal
      sekme**: `tabs()`'ta yok, yalnız `filtersForTab()`'ta — ekran kendi arayüzünü
      çiziyor ama doğrulama paylaşılsın diye `updateTab()`'ı kullanıyor. Ayarlar >
      Güvenlik sekmesine giriş kartı kondu.
- [x] `system.security.manage` capability; `Version20260912100000` (ban kolonları +
      `cp_user_sessions` + `cp_password_history`); EN/TR 210 anahtar tam parite.
- [ ] **KALAN (bilinçli ertelendi):** sudo/re-auth modu — arayüzü olmayan bir ayar
      düğmesi (`security.sudo_minutes`) eklenip **geri çıkarıldı**; çalışmayan bir düğme
      hiç düğme olmamasından kötü. WebAuthn/passkey ikinci faktör T4.2 ile birlikte.
- **Kanıt:** `cp:security:audit` varsayılan kurulumda 60/100 ve 6 orta bulgu veriyor;
  CSP `strict`, WAF `block`, HSTS ve 2FA zorunluluğu açıldığında puan yükseliyor —
  denetleyici ayarın kaydedilmiş olmasına değil, isteğin gerçekten nasıl işlendiğine
  bakıyor.

---

### TIER 3 — Platform / ops (SaaS · ERP · CRM)  `[ ]`

#### T3.1 — Gerçek async transport + bildirim primitifi  `[x]` ✅ (2026-09-12 — B → A+)
- [x] `messenger.yaml` → Doctrine `async` + `failed`; retry/backoff; `symfony/doctrine-messenger`
- [x] Mail + notification işleri kuyruğa; webhook **bilinçli olarak** `cp_async_jobs`'te kaldı
- [x] `NotificationDispatcher` primitifi: in-app + mail + digest; kanal tag'i + `contributions.yaml` `notification_types`
- [x] `Notification` entity + kullanıcı tercih matrisi (`User::$data`)
- [x] Worker/supervisor notu (AACP kuyruk ekranı) + `/aacp/queue` çift kuyruk UI + dashboard KPI
- [x] **GC2:** Forum `forum_notifications` → `cp_notifications` + tablo drop (`Version20260912150000`)
- [ ] **KALAN (bilinçli hibrit):** webhook'ların Messenger'a taşınması
- **Kanıt:** Editöryel geçiş → yazara `cp_notifications` + `messenger_messages` birikir; worker down iken kuyrukta kalır; `cpalius.messenger.consume` / `messenger:consume async` boşaltır.
- **Alan notu:** İki kuyruk bilinçli ayrıldı — webhook SSRF/imza yolu AsyncJob'da olgun; Messenger mail/bildirim için Symfony-native failed/retry sunuyor. AACP ikisini ayrı KPI olarak gösterir, tek sahte sayı üretmez.

---

#### T3.2 — ~~Organization / Tenant gerçek entity~~ **İPTAL** (2026-09-12, kullanıcı kararı)
- ~~`Organization` / membership / TenantContext / per-org override / AACP~~
- **Gerekçe:** Multisite/org şu anda öncelikli değil; SaaS tenant ihtiyacı netleşene
  kadar çekirdeğe erken bağlamak borç üretir. `tenant_id` kolonu ve `TenantFilter`
  duruyor — T3.2 varlık modeli ertelendi, filter altyapısı değil.
- **Skor satırı 21:** C kalır; yol haritası maddesi iptal (T2.1 deseni).

#### T3.3 — Genel Flood / abuse servisi  `[x]` ✅ (TS kapsamında kapandı)
- [x] `FloodService` — `isAllowed(event, identifier, limit, window)`, `register()`,
      `count()`, `clear()`, `lock()`, `lockedUntil()`. Sabit sayaç değil **kayan
      pencere**: sabit sayaç pencere sınırında limitin iki katına izin verirdi.
      Tanımlayıcılar hash'lenerek anahtara giriyor — e-posta adresleri cache
      keyspace'inde düz metin durmuyor.
- [x] Login, kayıt, şifre sıfırlama, 2FA doğrulama, CSP raporu → ayarlanabilir limitler
- [x] AACP ayar ekranı (Güvenlik Merkezi > Akış ve kaba kuvvet) + telemetri entegrasyonu
- **Kanıt:** "IP başına saatte 5 başarısız login" → 6.'da engellenir, telemetriye düşer.
- **Alan notu:** `$failOpen` bilinçli bir parametre. Login'de **true** — Redis düşerse
  bütün operatörleri kendi sitelerinden kilitlemek, kaba kuvvete birkaç dakika açık
  kalmaktan daha kötü bir arıza. Anonim suistimal uçlarında **false**.

#### T3.4 — Migrate API (WP / Drupal / CSV import)  `[~]`  **Faz A bitti (2026-09-12)**
- [x] `MigrationSource` → `transform` → `MigrationDestination` boru hattı, hepsi **tipli PHP
      arayüzü** (`#[AutoconfigureTag('cpalius.migration')]`) — YAML eklenti id'si yok
- [x] Idempotent + map tablosu `cp_migration_map` (migration_id + source_id **unique**):
      değişmemiş satır atlanır, değişmiş satır **yerinde güncellenir**, yeni satır yaratılır
- [x] `MigrationRunner` — dört garanti: dry-run varsayılan · satır başına izolasyon ·
      map **yazımdan sonra** yazılır · yeniden koşulabilir
- [x] `MigrationRegistry` — `dependsOn()` topolojik sırası; döngü ve tanınmayan bağımlılık
      **reddedilir** (sessizce atlanmaz)
- [x] `cp:migrate list|status|run|rollback` — `--apply` olmadan hiçbir şey yazmaz, `--limit`,
      `--json`, kabuk tamamlama
- [x] İlk sürücüler: akışlı `CsvSource` (BOM, özel ayraç, sütun sayısı denetimi) + `NodeDestination`
- [x] Testler: 32 unit + 8 entegrasyon (gerçek DB'de CSV → node, ikinci koşum, rollback)
- [x] **Faz B1 (2026-09-12):** **`Importer` modülü** — kaynak sürücüleri çekirdekte değil
      modülde (çekirdek CPalius yazar, modül yabancı sistemi okur). Akışlı `WxrReader`
      (`XMLReader`, namespace URI ile eşleşir), `WxrAuthorSource`/`WxrTermSource`/
      `WxrPostSource`, dört migration zinciri. Çekirdeğe: `ConfigurableMigrationInterface`
      (+`MigrationOption`/`Resolver`, `cp:migrate -o`), `MigrationLookup`, `UserDestination`,
      `TermDestination`, `NodeDestination`'a terim bağlama
- [x] **Faz B2 (2026-09-12):** medya + yorumlar. `AssetDestination` (çekirdek, `AssetManager`
      üzerinden — tek yazma kapısı bypass edilmiyor), `UploadsResolver` (yerel
      `wp-content/uploads`'tan çözer; path traversal reddedilir), `WordpressMediaIndex`
      (gövdedeki eski URL'leri yeniden yazar, **`-300x200` ve `-scaled` türevlerini** aynı
      asset'e indirger), `_thumbnail_id` → öne çıkan görsel. Yorumlar: `BlogCommentDestination`
      **Blog modülünde** (entity'nin sahibi yazmayı bilir), `WxrCommentSource` +
      `WordpressCommentMigration` Importer'da; thread, durum eşlemesi, e-posta ile hesap
      eşleştirme. **Satır 26: B → A**
- [ ] **Faz B3:** XenForo / MyBB / Joomla / Drupal 7-10 DB kaynak sürücüleri
      (**satır 26'yı A+'a taşıyacak olan**)
- [x] **Faz C (2026-09-12): Studio ekranı** `/admin/import` — kaynak sistem listesi (çalışanlar
      "Hazır", henüz olmayanlar **"Planlandı" damgasıyla, tıklanamaz**), sistem başına form,
      kuru çalıştırma raporu ve ayrı bir "Gerçekten aktar" düğmesi. Form alanları
      `options()`'tan türetiliyor (ekran ile komut satırı ayrışamıyor). Satır sınırı +
      kopyalanabilir `cp:migrate` komutu: tarayıcı isteği 300 MB'lık bir export'un yeri değil,
      ekran bunu saklamak yerine söylüyor. `importer.run` yetkisi, CSRF'li POST.
      `CsvNodeMigration` eklendi — CSV motoru Faz A'dan beri vardı ama kayıtlı bir migration'ı
      yoktu, yani kimse koşamıyordu; artık ekranda gerçek bir seçenek
- **Kanıt (Faz A):** `MigrateEndToEndTest` gerçek MySQL'de: CSV → 2 node · ikinci koşum
  **0 yaratma, 2 değişmemiş** · kaynak satırı düzenlenince **aynı node güncellenir** ·
  başlıksız satır tek başına düşer, komşuları geçer · çakışan başlıklar ayrı slug alır ·
  rollback tam olarak import edileni siler, elle yazılmış node'a dokunmaz.
- **Alan notu (lider zaafına karşı tasarım):** *En iyi: Drupal Migrate API.* Zaafları:
  (1) **dry-run yok** — ne yapacağını ancak yaptırarak öğrenirsin; (2) migration bir **YAML
  eklenti çorbası**, eklenti id'leri tipsiz string, yazım hatası koşunun ortasında patlar;
  (3) çekirdek tek başına **koşamaz** (`migrate_tools` contrib gerekir, çekirdek UI yalnız
  Drupal→Drupal); (4) rollback id map'in tuttuğu kadar iyi. CPalius: transform **düz PHP**
  (IDE tamamlar, PHPStan denetler), dry-run **varsayılan** ve aynı sayacı üretir, koşucu
  **çekirdekte**, rollback map'e göre **kesin** çünkü map'i koşucunun kendisi, hedefe
  yazdıktan *sonra* yazar. **D → B.** A değil: bu satır "CMS-ten CMS'e" diyor ve WXR/Drupal sürücüleri Faz B'de;
  motor A+ biçiminde, satırın kendisi sürücüler gelene kadar B.
- **Yan bulgu:** `Node`'u gerçekten (soft-delete değil) silen ilk kod bu oldu ve Doctrine'da
  patladı — revision listener'ın ürettiği `NodeRevision`'lar UoW'da kalıp silinen node'u
  işaret ediyordu. Üründe kimse node'u hard-delete etmediği için görülmemişti. `NodeDestination`
  revision'ları önce kaldırıyor; DB'deki `ON DELETE CASCADE` zaten vardı, eksik olan bellek
  içi grafiğin tutarlılığıydı.

#### T3.5 — DB log + admin log ekranı + mail log  `[x]` ✅ (2026-09-12 — A++)
- [x] `LogEntry` (`cp_log_entries`) + `symfony/monolog-bundle` + `DoctrineLogHandler` (buffered, terminate flush, never-throw) + `logging.purge` retention cron
- [x] AACP `/aacp/logs` — seviye/kanal/q filtresi, detay sayfası; capability `system.logs.manage`
- [x] `MailLog` (`cp_mail_logs`) — `CpMailerService` queue/send hooks + AACP `/aacp/logs/mail` yeniden gönder
- [x] Migration `Version20260912140000`; önceki bekleyen 5 migration da uygulandı
- **Kanıt:** `logger->error()` → DB satırı → `/aacp/logs`; test/async mail → MailLog; resend kuyruğa alır.
- **Alan notu:** AuditLog (entity-diff) ve SystemTelemetryLog (güvenlik) ayrı kaldı — tek tabloya sıkıştırılmadı.

#### T3.6 — Çekirdek update runner  `[x]`  (2026-09-12 — motor + komut + AACP ekranı)
- [x] `cp:update` — sıralı: pending migration → çekirdek update-hook → modül `upgrade()` → config import → cache rebuild (`Core/Update/UpdateRunner`)
- [x] Update-hook registry: `UpdateHookInterface` (`#[AutoconfigureTag]`) + `UpdateHookLedger`. Değişmeyen id ile tanımlı, sürümle etiketli, **birden fazla sürüm atlansa bile yayın sırasıyla** uygulanır
- [x] `--dry-run` (hiçbir şeye dokunmadan rapor) + `--json` (CI)
- [x] **Kapattığı asıl boşluk:** manifest sürümü ilerleyen bir modülün `upgrade()`'i yalnızca **yeniden etkinleştirmede** çalışıyordu. Operatörün her modülü elle kapatıp açması, üstelik hangisinin buna ihtiyacı olduğunu bilmeden, gerekiyordu — artık `cp:update` yapıyor
- [x] Devam ettirilebilirlik tasarımın kendisinde: ledger hook **döndükten sonra** yazılır (yarıda kesilen hook tekrar çalışır, "yapıldı" sanılmaz); bozuk ledger **boş** okunur, "hepsi bitti" değil; bir hook patlarsa komşuları çalışmaya devam eder; **yalnızca migration hatası** boru hattını durdurur
- [x] Ledger `cp_settings`'te (modül sürümlerinin zaten kullandığı konvansiyon) — kendi tablosu olsaydı, o tabloyu yaratan koşumu kaydedemezdi
- [x] `cp:doctor`'a `updates` kontrolü bağlandı: doctor geride kalmışlığı fark eder, update ileri taşır
- [x] Testler: `UpdateRunnerTest` (6, gerçek `final` işbirlikçiler + fixture hook) — sıralama, dry-run'ın hiçbir şey değiştirmemesi, bir-kez-çalışma, atlanan sürümler arası sıra, hata izolasyonu, bozuk-ledger fail-safe'i. `UpdateStepResultTest` (4)
- [x] AACP `/aacp/updates` ekranı — **kuru çalıştırmayla açılır, eylemle değil**: dağıtımdan sonra buraya gelen operatör önce neyin beklediğini görmek ister; ziyaret edilmekle değişiklik uygulayan bir sayfa canlıda açılamaz. Uygulama CSRF'li POST (bir güncelleme link takip ederek ulaşılabilir olmamalı). `system.update.manage` capability'si
- [x] Testler: `AacpUpdateControllerTest` (5) — **sayfayı gerçekten render ederek**: GET hiçbir satır yazmıyor (`cp_settings` sayımı sabit), sahte token reddediliyor, apply beş adımı da raporluyor, capability yetkisizi kesiyor. (Bir sayfanın login'e 302'lenmesini test etmek firewall'ı kanıtlar, ekranı değil.)
- **Kanıt:** `cp:update --dry-run` gerçek kurulumda beş adımı da sırayla raporluyor ve hiçbir şeye dokunmuyor; `--fail-on` yok çünkü çıkış kodu zaten başarısızlığı taşıyor. İkinci koşumda tamamlanan iş atlanıyor.
- **Alan notu:** Drupal `update.php` + `drush updb`, WordPress `wp core update-db` aynı işi görür ama ikisi de **veri düzeltmelerini** ayrı bir mekanizmaya bırakır (Drupal `hook_update_N`, WP sürüm karşılaştırmalı elle kod). CPalius'ta hook'lar tipli bir arayüz, ledger'ı var, sürüm sırası garantili, `--dry-run` ile önce prod'da sorulabiliyor ve aynı motor hem kabuktan hem AACP'den çalışıyor. **B → A** (A+ değil: modül paket deposu / tek-tık kurulum yok — bkz. skor satırı 35).

---

### TIER 4 — Headless / API  `[ ]`

#### T4.1 — Kullanıcı-bağlamlı API auth  `[ ]`
- [ ] Personal access token (kullanıcı başına, scope'lu) + OAuth2/OIDC opsiyonu
- [ ] API gateway → hem servis key hem kullanıcı token'ı
- [ ] Token yönetimi: hesap ekranı + AACP
- **Kanıt:** Mobil uygulama kullanıcı token'ıyla `/api` çağırır, kendi izinleriyle sınırlı.

#### T4.2 — Otomatik JSON:API koleksiyon uçları + OpenAPI  `[ ]`
- [ ] Bundle + `#[CpResource]` başına otomatik koleksiyon endpoint: filter/sort/paginate/sparse-fieldset
- [ ] İçerik negotiation + tutarlı hata formatı
- [ ] OpenAPI şema üretimi (`cp:api:openapi`)
- **Kanıt:** `/api/entity/post?filter[featured]=1&sort=-createdAt&page[size]=10` çalışır; OpenAPI şeması üretilir.

#### T4.3 — Responsive image + media entity (oEmbed video)  `[ ]`
- [ ] `ImageStyle` — srcset breakpoint setleri, WebP/AVIF negotiation, lazy
- [ ] `<picture>` / `srcset` Twig helper'ı
- [ ] Media entity tipleri: image, file, **remote video (YouTube/Vimeo oEmbed)**, audio, document
- [ ] Media library UI
- **Kanıt:** "Video sitesi": YouTube URL yapıştır → media entity → node'a reference → responsive embed.

---

### TIER 5 — Geliştirici deneyimi (DX)  `[ ]`

#### T5.1 — Maker paketi  `[ ]`
- [ ] `cp:make:module|resource|entity|field-type|hook|cron|api|settings|admin-controller`
- [ ] CPalius konvansiyonlarına uygun iskele (migration dahil)

#### T5.2 — `cp:doctor` + `cp:debug:*`  `[x]`  (2026-09-12)
- [x] `cp:doctor` — dokuz salt-okunur kontrol, altı grupta: `migrations` (bekleyen + diskte olmayan ama kayıtlı), `modules` (karantina), `capabilities` (rolde olup kayıtlı olmayan yetki = sessiz hiçlik; kayıtlı olup hiçbir rolde olmayan), `translations` (**satır sayısı değil, düzleştirilmiş anahtar kümesi** — eşit satırlı iki dosya farklı anahtar taşıyabilir), `environment` (prod'da debug, eksik eklenti, yazılamayan dizin, kurtarma kapısı token'ları), `security` (duruş puanı köprüsü), `updates` (T3.6)
- [x] `--fail-on` (CI kapısı; hatalı eşik **reddedilir**, sessizce kapıyı kapatmaz), `--only`, `--list`, `--all`, `--json`
- [x] **Doctor'ın kendi izolasyonu:** bir check patlarsa koşu durmaz, exception bir bulguya dönüşür. `cp:doctor` zaten bozuk kurulumlarda çalıştırılacak — ilk gerçek problemde ölen teşhis aracı tam ihtiyaç anında işe yaramaz ("Core Never Dies" araç katmanına taşındı)
- [x] Çeviri anahtarı **kullanmıyor**, düz metin üretiyor: çeviri kataloğunun kendisi bozuk olabilir
- [x] `#[AutoconfigureTag('cpalius.doctor.check')]` — bir modül kendi kontrolünü katkılayabilir, çekirdek modülü tanımadan
- [x] Testler: `DoctorTest` (7), `DoctorFindingTest` (15), `TranslationParityCheckTest` (8, gerçek geçici dosya ağacı)
- [x] `cp:debug <topic>` — altı konu tek komutta: `capabilities` (hangi rol tutuyor), `hooks`, `cron` (DB + attribute + flat-file bir arada), `entity-types` (keşfedilen bayraklarla), `fields` (kayıtlı tipler + tanımlar, kayıtsız tip **UNREGISTERED** damgalı), `resources`. `--filter` + `--json` + kabuk tamamlama
- [x] **Neden tek komut:** altı ayrı sınıfın farkı ikişer satırlık veri şekillendirmesi; ayrı dosyalar biri büyüdüğü an birbirinden kopardı
- [x] **Neden var:** CPalius kendini büyük ölçüde attribute ile keşfediyor (`#[CpHook]`, `#[CpCronJob]`, `#[CpResource]`, `#[CpEntityType]`, `#[CpFieldType]`). Bu, modüle az törenle çok erişim veriyor — ama geliştiriciye "benimki kaydoldu mu" sorusunun cevabını tetikleyip bakmaktan başka yolla vermiyor. Hook noktasındaki yazım hatası **hata değil sessizlik** üretiyor
- [x] Testler: `DebugCommandTest` (11) — altı konunun her biri kendi kayıt defterini gerçek API'siyle okuyor, filtre daraltıyor, JSON sütun adlarıyla anahtarlı, bilinmeyen konu **boş tablo değil hata** veriyor (yazım hatasına boş liste dönmek "hiçbir şey kayıtlı değil" diye okunurdu)
- **Alan notu:** Drupal `drush` alt komutlarıyla benzerini verir ama çekirdekte değil, contrib araçta. Symfony'nin `debug:*` ailesi framework nesnelerini gösterir, CPalius'un kendi sözleşmelerini değil. **C → A.**
- **Kanıt:** Bu komutun yazılma sebebi gerçek bir olaydı — beş migration birkaç oturum boyunca uygulanmadan durdu ve **hiçbir belirti vermedi**, çünkü onlara ihtiyaç duyan özellikler sessizce bozulacak şekilde yazılmıştı. `cp:doctor` CI'da 16. adım olarak koşuyor.

#### T5.3 — Modül test kiti  `[~]`  (2026-09-12 — taban + DB reset bitti)
- [x] `App\Tests\Support\IntegrationTestCase` — kernel boot + şema reset + `authenticateAs()` / `pushRequest()` / `browser()`. Önceden bu blok **15 dosyada, 4 farklı yazımla** kopyalanmıştı
- [x] `App\Tests\Support\IntegrationSchema` — **metadata'ya değil, veritabanına** bakarak tüm tabloları düşürür. `dropSchema($metadata)` silinen bir entity'nin tablosunu kaldıramaz; o yetimlerin FK'leri canlı tabloların düşürülmesini engeller ve `createSchema` "zaten var" der. Entegrasyon paketinin **kararsızlığının kökü buydu** (PHPUnit başarısız testleri öne aldığı için her koşumda farklı test patlıyordu)
- [x] Fixture modül deseni zaten vardı (`cp-core/tests/Fixtures/Modules/`), `HookFixture` gerçek `#[CpHook]` servisiyle
- [ ] **KALAN:** entity factory'ler; dokümante edilmiş örnek modül testi
- [ ] Dokümante edilmiş örnek modül testi

#### T5.4 — Kalan config provider'lar + AACP config UI (Faz C2) + config split  `[ ]`
- [ ] Provider: workflow, menu, node type, display mode, text format, path pattern, cron
- [ ] AACP config import/export/diff ekranı
- [ ] Config split (dev vs prod override)

#### T5.5 — Geliştirici el kitabı  `[ ]`
- [ ] Modül anatomisi, Field API, Resource API, hook referansı, capability referansı, tema rehberi
- [ ] `cp-content/` altında veya ayrı docs sitesi

---

### TIER 6 — Field API tamamlama  `[ ]`

#### T6.1 — Eksik alan tipleri  `[ ]`
- [ ] `Money` / `Price` (ERP — cent tabanlı, currency), `Link` (title+attrs), `Address` (ülke-farkında),
      `Telephone`, `Geo` / `Map`, `List` (config'li allowed values), `Duration` / `Time`,
      nested/composite reference (paragraphs benzeri), `Json` / raw
- [ ] `SequenceGenerator` (fatura no vb. — ERP)

#### T6.2 — Alan validasyon config'i + PII şifreleme  `[ ]`
- [ ] UI'dan alan-bazlı kural: regex, min/max, bundle-içi unique, default value
- [ ] `encrypted: true` alanlar → at-rest şifreleme (KYC / sağlık / finans)

---

## 4. İLERLEME GÜNLÜĞÜ

> En yeni en üstte. Her oturum sonunda: değişen dosyalar, doğrulama, kalan risk.

### 2026-09-12 (25) — T3.4 Faz C: Studio'daki içe aktarma ekranı

**Neden bu adım (kullanıcı geri bildirimi):** Modül CLI-first yazılmıştı; panelde yalnızca
"modül ayrıntıları" görünüyor, Studio'da modülü kullanacak bir yer yoktu. Aktifken
kullanılamayan bir modül yarım demektir — arayüz "Faz C" diye ileriye atılmıştı, öne alındı.

**Ekran:** `/admin/import`
- **Kaynak listesi.** Çalışan sistemler "Hazır" rozetiyle ve bir düğmeyle; henüz olmayanlar
  (XenForo, MyBB, Drupal, Joomla) **"Planlandı" damgasıyla ve tıklanamaz** olarak. Gizlemek
  operatörü "acaba bir yerde var mı" diye arattırırdı; çalışmayan düğme koymak ise daha
  kötüsü olurdu. Damgalı hâli tek dürüst sürüm.
- **Uygunluk beyan edilmiyor, türetiliyor:** bir sistem, o önekte kayıtlı migration varsa
  "hazır" sayılıyor. Elle tutulan bir bayrak koddan kopardı.
- **Form `options()`'tan üretiliyor.** Ekranla komut satırı aynı kaynaktan besleniyor,
  dolayısıyla ayrışamıyorlar. Bir modül yeni bir seçenek eklerse form kendiliğinden büyür.
- **Ziyaret yazmaz.** AACP güncelleme ekranıyla aynı kural: URL'e gelmek veriyi değiştirmez.
  Form gönderimi **kuru çalıştırma** yapar; yazmak ayrı ve adı konmuş bir düğme + CSRF'li
  POST ister. `mode=apply` her gönderimde yeniden seçilmek zorunda, böylece açık kalmış bir
  sayfa tazelenerek yazmaya dönüşemiyor.
- **Satır sınırı ve kopyalanabilir komut.** Bir tarayıcı isteği 300 MB'lık bir export'un yeri
  değil: PHP süre sınırı ortasında keser. Map yarıda kalanı güvenli kılıyor ama yine de kötü
  bir deneyim. Ekran ne olduğu konusunda dürüst — denemek ve küçük/orta siteler için — ve
  gerisi için **operatörün girdiği değerlerle doldurulmuş** `cp:migrate` satırını gösteriyor.

**Yan kazanımlar:**
- **`CsvNodeMigration`.** CSV motoru Faz A'dan beri vardı ama kayıtlı bir migration'ı yoktu:
  yani başka kodun üstüne inşa edebileceği, kimsenin koşamadığı bir yetenekti. Kaydedildi ve
  ekranda gerçek bir seçenek oldu.
- **Adım adları artık çevriliyor.** Çeviri anahtarları Faz B1'de yazılmış ama kullanılmamıştı;
  panel Türkçeyken adımlar İngilizce görünüyordu. Cron ve hook ekranlarındaki desen kullanıldı:
  anahtar varsa çeviri, yoksa migration'ın kendi etiketi — böylece yeni bir kaynak ekleyen
  modül asla ham anahtar olarak render edilmiyor.
- **`importer.run` yetkisi** (tek yetki, bilinçli: içe aktarabilen zaten kullanıcı yaratabilir,
  içerik yayımlayabilir ve dosya yazabilir; "görüntüle"/"koş" diye bölmek yalnızca ekranı
  okuyabilen bir rol ve yarısının güvenli olduğu yanılgısı üretirdi).

**Testler (`ImportScreenTest`, 10 test):** sayfa gerçekten render ediliyor. Ziyaretin hiçbir
şey yazmadığı, token'sız POST'un reddedildiği, kuru çalıştırmanın yazmadığı, "gerçekten aktar"ın
**gerçekten 2 node yarattığı**, satır sınırının tuttuğu ve hatalı dosya yolunun sayfada mesaj
olarak döndüğü doğrulanıyor. CSRF token'ı **render edilmiş formdan okunuyor** — hem tarayıcının
yaptığının aynısı hem de daha güçlü bir iddia: sayfanın gerçekten gönderilebilir bir token
bastığını kanıtlıyor.

**Not:** Bu testler `container()->get()` ile private servis çekmiyor. Çekirdeğin eşdeğer testi
bunu yapıyor ve 50 kayıtla baseline'da duruyor; `phpstan.neon.dist` "baseline donmuş borç, yeni
kod tam seviyede" dediği için yeni dosya o yola sokulmadı.

### 2026-09-12 (24) — T3.4 Faz B2: medya ve yorumlar — satır 26 **B → A**

**Neden bu adım:** Faz B1'den sonra satır 26 bilinçli olarak B'de bırakılmıştı, gerekçesi
şuydu: "medya yok, gerçek bir taşımada görseller kırılır". Bu adım tam olarak onu kapattı.

**Medyanın zor kısmı dosyayı kopyalamak değil, markup'ı düzeltmek.** İçerik gelir, içindeki
`<img>` etiketleri eski alan adını göstermeye devam eder; eski site kapanana kadar her şey
çalışıyor görünür, kapandığı gün bütün görseller aynı anda kaybolur — kimsenin bakmadığı bir
zamanda. İki WordPress ayrıntısı bunu basit bir ara-değiştirden çıkarıyor:
1. **Gövde neredeyse hiçbir zaman orijinali göstermez.** WP türev üretir ve markup'a
   `foto-300x200.png` yazar, export'taki attachment ise `foto.png`'dir. İkisi de aynı asset'e
   düşmeli, yoksa her satır içi görsel kırık kalır. Boyut soneki (`-WxH`) ve çok büyük
   yüklemelere eklenen `-scaled` aramadan önce soyuluyor.
2. **Aynı dosya bir sitenin ömrü boyunca birçok mutlak önekle geçer** (http/https, www'lı ve
   www'suz, CDN). Eşleştirme tam URL üzerinden değil, **uploads'a göreli kuyruk** üzerinden
   yapılıyor; hepsini tek kural karşılıyor.

**Güvenlik tarafı:** `AssetDestination` satırı doğrudan yazmıyor, `AssetManager`'dan geçiyor —
o tek yazma kapısı (SEC-01/SEC-02): MIME'ı içerikten `finfo` ile tespit eder, allowlist dışını
reddeder, diskteki adı **doğrulanmış tipten** üretir, içerik hash'iyle tekilleştirir. Yabancı
bir uploads klasörünü içeri almak tam da ".jpg diye adlandırılmış .php" senaryosunu davet
ediyor; bypass edilseydi bu bir dosya yükleme açığı olurdu. Yan etki: **SVG reddediliyor**
(script taşıyan format) ve bu bir *hata* olarak raporlanıyor — dosya gerçekten gelmedi.
`UploadsResolver` ayrıca `realpath` ile kapsama denetimi yapıyor: export'un ismindeki `..`
ile uploads klasörünün dışına çıkan bir yol reddediliyor (testle kanıtlandı).

**Yorumlar — mimari gerilim ve çözümü:** `BlogComment` Blog modülünün entity'si, WordPress
bilgisi ise Importer'ın işi. İkiye ayrıldı: **hedef Blog'da** (`Modules\Blog\Migrate\`,
kendi entity'sinin kurallarını bilen taraf), **kaynak + migration Importer'da**. Başka bir
forum importer'ı aynı hedefi yeniden yazmak yerine kullanacak. Importer artık
`requires: {Blog}` beyan ediyor — açık ve aktivasyonda denetleniyor.
- Bilinmeyen durum **"pending"** olur, "approved" değil: gerçek bir yorumun moderatör
  beklemesinin bedeli gecikme, eski sitenin gizlediği bir şeyi yayımlamanın bedeli onu
  yayımlamaktır.
- Post'u aktarılmamış yorum **atlanır, hata sayılmaz** — posts migration'ı sayfaları ve
  auto-draft'ları bilerek dışarıda bırakıyor, onların yorumlarının gidecek yeri yok; her
  birine hata yazmak gerçek sorunları gürültüye gömerdi.
- **IP adresleri taşınmıyor.** Kişisel veri, artık var olmayan bir sitede yıllar önce
  birinin nerede olduğunu anlatıyor ve burada hiçbir şey onlara göre davranmıyor; taşımak
  hiçbir işe yaramayan veri için saklama yükümlülüğü üstlenmek olurdu.

**Kuru çalıştırmanın dürüst sınırı:** Dry-run map'e yazmadığı için, başka bir migration'a
bağlı olan satırlar bağlanacakları kaydı bulamaz ve *atlandı* sayılır. Uydurmak yerine komut
bunu söylüyor: birden fazla migration koşan her kuru çalıştırma bir not basıyor.

**Bir düzeltme daha:** Test ortamı için `flysystem.yaml` geçersiz kılması eklendi. Yoksa
asset yazan her test geliştiricinin **gerçek `public/uploads`** klasörüne dosya bırakırdı.

**Doğrulama:** PHPStan L6 temiz · php-cs-fixer temiz · **1011 unit + 64 modül testi yeşil** ·
`cp:migrate list` altı migration'ı doğru bağımlılık sırasında gösteriyor
(attachments/authors/categories/tags → posts → comments) · gerçek fixture ile kuru çalıştırma
hiçbir şey yazmadan doğru sayıları veriyor.

**Kalan:** Faz B3 (XenForo/MyBB/Joomla/Drupal DB sürücüleri — satır 26'yı A+'a taşıyacak) ·
Faz C (AACP sihirbazı).

### 2026-09-12 (23) — T3.4 Faz B1: Importer **modülü** + WordPress WXR

**Kapsam kararı (kullanıcı):** Kaynak sürücüleri çekirdeğe yük bindirmesin, **modül**
olsun. Doğru ayrım şu çıktı ve kapsam süzgeciyle birebir örtüşüyor:
- **Çekirdek** kendi entity'lerine *yazmayı* bilir: motor, map, koşucu, `cp:migrate`,
  jenerik kaynaklar (CSV) ve `Node`/`User`/`Term` hedefleri.
- **Modül** yabancı sistemleri *okur*: WXR, ileride XenForo/MyBB/Joomla/Drupal DB.
  Çekirdek WordPress'in ne olduğunu hiç öğrenmiyor. Taşıma bitince modül kapatılır,
  bilgi de onunla gider.

**Lisans notu — kopyalama yok.** Kullanıcı referans olarak WordPress Importer (GPLv2+),
MyBB Merge System ve XenForo importer'ını depoya koydu. CPalius **proprietary**, dolayısıyla
bu kodlardan alıntı yapılamaz. Ama dosya formatları ve tablo şemaları telif konusu değil:
araçlar **format dokümantasyonu** olarak okundu, kod sıfırdan yazıldı. Klasör
`.gitignore`'a alındı — yabancı lisanslı kod bu depoya girmemeli.

**Çekirdeğe eklenen üç eksik** (Faz B bunları ortaya çıkardı):
- `ConfigurableMigrationInterface` + `MigrationOption` + `MigrationOptionResolver` —
  "şu dosyadan içe aktar". `withOptions()` **yapılandırılmış kopya** döndürür, konteynerdeki
  servis el değmemiş prototip kalır; aynı süreçte iki farklı dosyanın importu birbirine
  bulaşamaz. Seçenekler `id()`'yi **değiştirmez** (id map'i anahtarlıyor), sonucu açıkça
  yazıldı: bir migration id'si = bir kaynak sistem.
- `MigrationLookup` — "authors migration'ı WordPress kullanıcı 3'ü neye çevirdi?". Çok
  entity'li importu mümkün kılan şey bu; olmazsa her importer ya isimle eşleştirir (aynı
  adı taşıyan iki farklı yazarı sessizce birleştirir) ya da yazarsız içerik aktarır.
- `UserDestination` + `TermDestination`. Parolalar **taşınmıyor**: yabancı hash'i yeniden
  hash'lemek zayıf primitifi güçlü görünen bir sargının altında yaşatır, parola uydurmak da
  herkese kimsenin seçmediği bir kimlik verir. Hesaplar kullanılamaz parolayla gelir,
  parola sıfırlamadan girilir — bu aynı zamanda adresin hâlâ onlarda olduğunu kanıtlar.

**WordPress sürücüsü:** `WxrReader` **akışlı** (`XMLReader`) — WP'nin kendi importer'ı bütün
export'u `DOMDocument`'a yükler, 300 MB'lık bir export'un `memory_limit`'te ölmesinin sebebi
tam olarak budur. Namespace **URI ile** eşleştiriliyor, `wp:` önekiyle değil: önek yerel bir
takma addır, başka bir araçla yazılmış geçerli bir dosya aksi hâlde boş okunurdu.
Zincir: `wordpress.authors → wordpress.categories → wordpress.tags → wordpress.posts`,
`dependsOn()` ile sıralanıyor.

**İki tasarım kararı, ikisi de veri kaybına karşı:**
- **Yayımlanmamış her şey taslak olur.** WP'de publish/draft/pending/private/future/inherit
  var, CPalius'ta üç durum. Private bir yazının yeni *herkese açık* sitede belirmesi bir
  ifşa, sessizce atılması ise veri kaybı. Taslak, ikisi de olmayan tek seçenek.
- **E-postasız yazar atlanır**, uydurulmaz: parola gelmediği için adresi olmayan hesap
  asla kurtarılamaz, üstelik ileride düzgün kayıt olabilecek birinin adını işgal eder.

**Yol boyunca bulunan üç gerçek hata:**
1. **Hiçbir modül etkinleştirilemiyormuş.** `ModuleActivator`'ın ön-uçuş kontrolü
   `lint:yaml`'ı `--parse-tags` olmadan koşuyor, çekirdeğin kendi `services.yaml`'ı ise
   `!tagged_iterator` kullanıyor → her aktivasyon "modül sözleşmeyi ihlal etti" diye
   **modülü karantinaya alıyordu**. Hata denetleyicideydi, suçlanan modüldü.
2. **`UserDestination` profil verisini siliyordu.** `User` birinci sınıf profil alanlarını
   (`first_name`, `bio`, `signature`, avatar…) JSON kolonunun *içinde* tutuyor;
   `setData()` ile tüm diziyi değiştirmek, export'un bilmediği her şeyi yok ediyordu —
   üstelik sessizce, profilini burada doldurmuş bir kullanıcının ikinci importunda.
   Artık birleştiriliyor ve tipli setter'lar sonra çalışıyor. Regresyon testi yazıldı.
3. **Kanal seviyesi satırların hepsi kayboluyordu.** `SimpleXMLElement::children()`
   argümansız çağrılınca yalnızca *varsayılan* namespace'teki çocukları döndürüyor, yani
   `<wp:author>` parçası "çocuğu yok" diye okunuyordu. `<item>` namespace'siz olduğu için
   yazılar çalışıyor, yazar/kategori/etiket sessizce 0 satır dönüyordu — hata yok, çünkü
   hiç satır üretmeyen bir kaynak, boş bir export'tan ayırt edilemez.

**Yeni `modules` test paketi:** `phpunit.xml.dist` + CI'da ayrı adım. Modüller çekirdeğin
dışında yaşıyor, kanıtları da öyle yaşamalı — yoksa bir modülün testleri çekirdeğin test
klasörüne sızar ve modül silindiğinde orada kalır.

**Doğrulama:** PHPStan L6 temiz · php-cs-fixer temiz · **1011 unit + 87 entegrasyon +
36 modül testi yeşil** · `cp:migrate list` dört migration'ı bağımlılık sırasında gösteriyor ·
gerçek WXR dosyasıyla kuru çalıştırma: yazarlar 2 işlendi/1 atlandı, kategoriler 2,
etiket 1, yazılar 2 (auto-draft ve page doğru şekilde dışarıda), **hiçbir şey yazılmadı**.

**Kalan (Faz B2):** medya/ek dosyalar ve yorumlar — bunlar olmadan gerçek bir site
taşımasında görseller kırılır, satır 26 bu yüzden **B'de bırakıldı**. Sonra XenForo/MyBB.

### 2026-09-12 (22) — T3.4 Migrate API, Faz A: motor

**Neden bu adım:** Skor kartında kalan tek `D` satır 26'ydı ve orası Drupal'ın en güçlü
olduğu yer. Kural gereği önce liderin zaafları isimlendirildi, tasarım onlara karşı yapıldı:
Drupal'da **dry-run yok**, migration bir **YAML eklenti çorbası** (eklenti id'leri tipsiz
string, yazım hatası koşunun ortasında patlar), **çekirdek tek başına koşamaz**
(`migrate_tools` contrib gerekir; çekirdek UI yalnız Drupal→Drupal), ve rollback id map'in
tuttuğu kadar iyi.

**Ne yazıldı (`cp-core/src/Core/Migrate/`):**
- `MigrationInterface` / `MigrationSourceInterface` / `MigrationDestinationInterface` +
  `MigrationRow` — hepsi tipli PHP. Transform düz PHP metodu: IDE tamamlıyor, PHPStan
  denetliyor. YAML yok.
- `MigrationRunner` — **dört garanti**: (1) dry-run varsayılan ve gerçek koşumun aynı
  sayaçlarını üretir, hiçbir şeye dokunmadan; (2) satır başına izolasyon — patlayan satır
  kaynak id'siyle raporlanır, koşu devam eder (Law 2.2 veriye uygulanmış); (3) map
  **hedefe yazdıktan sonra** yazılır, `UpdateHookLedger` ile aynı disiplin — arada çöken
  satır tekrar denenir, "yapıldı" sanılmaz; (4) tasarım gereği tekrar koşulabilir —
  değişmemiş satır atlanır, değişmiş satır **yerinde güncellenir**.
- `cp_migration_map` + `(migration_id, source_id)` **unique** — garantinin kendisi veritabanında.
  Checksum sıraya duyarsız, yani sütun sırası değişen bir export her satırı "değişmiş"
  göstermiyor.
- `MigrationRegistry` — `dependsOn()` topolojik sırası. Döngü **ve tanınmayan bağımlılık**
  reddediliyor: bağımlılık id'sindeki yazım hatası sessizce atlanırsa, listenin önlemek için
  var olduğu yetim referans hatası aynen oluşur.
- `cp:migrate list|status|run|rollback` — `--apply` olmadan **hiçbir şey yazmaz**
  (çoğu importer'ın tersi; yanlışlıkla dry-run'ın bedeli rapor okumak, yanlışlıkla 40 000
  satır import etmenin bedeli yedekten dönmek). `--limit`, `--json`, kabuk tamamlama.
- `CsvSource` (fgetcsv ile **akışlı** — bellek maliyeti dosya boyutundan bağımsız; Excel'in
  BOM'u ilk sütun adını bozmasın diye temizleniyor) + `NodeDestination` (node kolonu olmayan
  her alan hibrit modelin JSON'una gider — import için şema değişikliği gerekmemesinin sebebi).

**Yan bulgu — `Node`'u gerçekten silmek kırıkmış:** Rollback, üründe `em->remove()` ile bir
Node'u silen **ilk kod** oldu ve Doctrine "NodeRevision#node üzerinden yeni bir entity
bulundu" diye patladı. Sebep: revision listener'ın ürettiği `NodeRevision`'lar UoW'da yönetili
kalıp silinen node'u işaret ediyor. Kimse fark etmemişti çünkü ürünün bütün silme yüzeyleri
node'u **çöpe atıyor** (`#[SoftDeletable]`). DB'deki `ON DELETE CASCADE` zaten vardı; eksik
olan bellek içi grafiğin tutarlılığıydı. `NodeDestination` revision'ları önce kaldırıyor.

**Testler:** 32 unit (`MigrationRunnerTest` 16 — dört garantinin her biri ayrı ayrı;
`CsvSourceTest` 12; `MigrationRegistryTest` 7; `MigrationRowTest` 7) + `MigrateEndToEndTest`
8 entegrasyon, **gerçek MySQL'de**: ikinci koşum 0 yaratma, düzenlenen satır aynı node'u
güncelliyor, dry-run veritabanına dokunmuyor, başlıksız satır tek başına düşüyor, çakışan
başlıklar ayrı slug alıyor, rollback elle yazılmış node'a dokunmuyor, süreç dışından silinmiş
node rollback'i bozmuyor.

**Doğrulama:** PHPStan L6 temiz · php-cs-fixer temiz · **1011 unit + 79 entegrasyon yeşil** ·
`lint:container` dev OK · `lint:yaml --parse-tags` OK · `cp:migrate list` gerçek konteynerde
çalışıyor.

**Kalan:** Faz B (WXR + Drupal DB sürücüleri; satır 26'yı A'ya taşıyacak tek şey) · Faz C
(AACP sihirbazı) · **`Version20260912170000` dev veritabanına uygulanmadı.**

### 2026-09-12 (21) — AACP konsolidasyonu + CSP nonce'unun gerçekten bağlanması

**Bağlam:** Oturum, çalışma ağacında yarım kalmış bir AACP işiyle açıldı (commit
edilmemiş 33 dosya). Önce o iş bitirildi ve commit'lendi, sonra onun açtığı kapıdan
çok daha büyük bir bulgu çıktı.

**Commit 1 — AACP konsolidasyonu:**
- Düzenlenebilir hardening ayarları (headers, WAF, flood, parola, 2FA, oturum)
  Güvenlik Merkezi'nin özel ekranından **System Settings → Security** sekmesine taşındı;
  captcha ile birlikte bölüm kartları olarak çiziliyor. Merkez'de yalnızca gerçekten
  canlı olan üç şey kaldı: duruş denetimi, IP ban'ları, aktif oturumlar. Eski POST
  rotası BC yönlendirmesi olarak duruyor (yer imi 404 vermesin).
- `CpImportMapExtension` — Symfony'nin `importmap()` fonksiyonu nonce geçirmeye izin
  vermiyor; override edildi, üretilen her `<script>` artık nonce taşıyor.
- Dashboard'a 24 saatlik güvenlik şeridi (telemetri özeti + ban istatistiği). Ziyaretçi
  istatistikleri güvenlik telemetrisi açıkken artık sıfırlanmıyor — ikisi birbirinin
  alternatifi değil, tamamlayıcısı.
- `QueryCounter` artık `information_schema` / `pg_catalog` / `performance_schema` /
  `sys` / `mysql` saymıyor. Doctrine şema introspection'ı tek istekte bunlara meşru
  olarak onlarca kez vuruyor (AACP güncelleme dry-run'ı tam olarak bunu yapıyordu) ve
  N+1 olmayan bir ekranda dev N+1 guard'ını tetikliyordu. Unit testi yazıldı.
- Cron işleri, hook noktaları ve taxonomy vocabulary'leri çevrilmiş etiket gösteriyor,
  ham anahtar basmak yerine makine adına düşüyor. Queue ekranı standart kart/grid
  tasarım sistemine taşındı (kendi `<h1>`'ini taşıyan son sayfaydı).

**Yarım işte bulunan iki kırık:** `aacp-security.js` hem `importmap.php`'de entrypoint
hem de `app.js`'te tek seferlik dinamik `import()` ile yükleniyordu — on bir kardeş
script'in hepsi şablonundan `importmap()` ile yükleniyor, bu tek istisnaydı; konvansiyona
çekildi. Ayrıca bir dosya CRLF'e kaymıştı, resmî fixer ile normalize edildi (içerik
farkı olmadığı satır-sonu-duyarsız diff ile doğrulandı).

**Commit 2 — asıl bulgu:** `CspNonceProvider` ve `csp_nonce_attr()` yardımcısı aylardır
vardı, **ama hiçbir şablon çağırmıyordu.** Sevk edilen 19 script etiketinin hiçbirinde
nonce yoktu. Bu "biraz zayıf" değil: strict modda `script-src` şu:

    'self' 'nonce-X' 'strict-dynamic' https:

ve `'strict-dynamic'` tarayıcıya `'self'` ile `https:`'i **yok saydırır** — yalnızca
nonce'lu script (ve onun yüklediği) çalışır. Yani en sıkı CSP modunu açmak cron
konsolunu, URL alias formunu, anasayfa builder'ını, profil sayfasını, iki Forum admin
ekranını, her giriş ve yorum formundaki captcha'yı ve referans temanın bundle'larını
öldürüyordu. Sertleştirme katmanı, saldırgan yerine siteyi indiriyordu — ve **sessizce**,
çünkü varsayılan balanced mod `'unsafe-inline'` veriyor ve tüm bu sınıfı gizliyor.

19'unun hepsi nonce'landı. Veri blokları (`application/json`, `ld+json`) bilinçli olarak
dışarıda: hiç çalıştırılmazlar, CSP onları denetlemez.

**Kanıt:** `TemplateScriptNonceTest` her koşumda `cp-core/templates`, `cp-content/modules`
ve `cp-content/themes`'i tarıyor, nonce'suz her etikette dosya:satır vererek kırılıyor.
Negatif kontrol yapıldı: cron şablonundan attribute çıkarılınca test kırmızı, geri
konunca yeşil. GC3'ün dersi burada da geçerliydi — mimari doğruydu, **bağlanmamıştı**.

**Doğrulama:** PHPStan L6 temiz · php-cs-fixer temiz · **969 unit + 71 entegrasyon
yeşil** · `lint:twig` 241 dosya · `lint:container` dev OK · `lint:yaml` OK.

**Kalan risk / karar:** `'strict-dynamic'` host ifadelerini yok saydığı için
`security.csp_script_src` ayarı strict modda **etkisiz** — operatör host ekler, hiçbir
şey olmaz. §0'da karar konusu olarak kaydedildi.

### 2026-09-12 (20) — Teknik borç: strict_types + stil sweep
**Amaç:** GC3 sonrası bilinçli olmayan borç — 49 dosyada `declare(strict_types=1)`
eksikti (`Kernel`, `Node`, `User`, `CPaliusVoter` dahil); finder altındaki stil sapması
tek commit'te kapanacaktı. 71 entegrasyon testi + pre-commit koruması varken risksiz.

**Yöntem (veri kaybı dersi):** özel regex betiği yok. Önce `php-cs-fixer --dry-run`
(531/900), sonra resmi `fix`. Uygulama sonrası: boş dosya = 0, HEAD'e göre %80+ küçülen
= 0, eksik `strict_types` = 0. Git blob hash'leri, status'taki sahte `M` satırlarının
içerik farkı olmadığını doğruladı (stat cache / CRLF uyarısı).

**Yan etki:** cs-fixer PHPDoc `@param` adlarını parametrelerle hizalayınca 4 baseline
`parameter.notFound` kaydı `ignore.unmatched` oldu → silindi. Baseline hata toplamı
**374 → 372**.

**Doğrulama:** PHPStan L6 temiz · cs-fixer 0/900 · **966 unit + 71 entegrasyon**
(Assertions: 271; 3 deprecation, exit 0) · `cp:doctor --fail-on=high` 0 ·
lint:container dev OK. İçerik diff: **272 dosya** (+721 / −768).

**Kalan:** T5.1 / T3.4 / T5.5 · satır 40 MySQL kararı · `sys@rootali.net` onayı ·
push henüz yok.

### 2026-09-12 (19) — GC3: kalite kapıları, kanıt borcu, T3.6 · **+ veri kaybı olayı**
**Bağlam:** Bu oturum bir denetimle başladı: yol haritası birçok satırda `A++` diyordu
ama o iddiaları üreten kodun **hiç testi yoktu**, ve projede CI, PHPStan yapılandırması,
README, LICENSE ya da SECURITY.md de yoktu. Teşhis şuydu: mimari değil, **kanıt** eksik.

**Kalite kapıları (yoktan var edildi):**
- `phpstan.neon.dist` **level 6** + `phpstan-baseline.neon` (mevcut borç donduruldu,
  yeni kod tam seviyede tutuluyor). `ignoreErrors` bilinçli boş: baseline bakiyeyi
  *sayılabilir* tutar, geniş desenli bir ignore aynı hatayı gelecekte de yutardı
- `.php-cs-fixer.dist.php` (@Symfony + strict_types + sıralı import). Kapatılan üç
  kuralın her birinin gerekçesi dosyada yazılı
- `.github/workflows/ci.yml` — 16 adım: lint ×3, **çekirdek izolasyon kuralı**
  (`use Modules\` grep'i artık makine denetimli), doctrine mapping, PHPStan,
  stil (yalnız değişen dosyalar), `cp:doctor`, unit + entegrasyon
- README (EN+TR) git geçmişinden geri alındı, LICENSE + SECURITY.md + `.env.example` yazıldı

**Güvenlik katmanı ilk kez test edildi** (alt-ajan, 537 test / 1342 assertion):
23 bulgu, 17'si düzeltildi. En kritik ikisi:
- **`SecretBox` mühürlü değildi** — yol haritası "mühürlü" diyordu, gerçekte AES-256-**CBC**,
  MAC yok. Artık AES-256-GCM; `v2:` öneki sayesinde eski değerler okunmaya devam ediyor,
  migration gerekmiyor
- **Çözülemeyen secret 2FA'yı sessizce kapatıyordu** (fail-open). APP_SECRET rotasyonu →
  tüm hesapların ikinci faktörü kapanır, arayüzde hâlâ "korumalı" görünürdü. Artık fail-closed
- Yanında: IPv4-mapped IPv6 yüzünden **çift yığınlı sunucuda IP ban listesi tamamen
  atlanıyordu**; allowlist koruması CIDR'ları hiç kapsamıyordu; `GREATEST()` MySQL dışında
  yeniden-ban yolunu kırıyordu; `SecurityAuditor` tanınmayan WAF değerini **"PASS"** sayıyordu
- Adil olmak gerekirse: `TotpGenerator` altı resmî RFC 6238 vektörünü ilk denemede geçti,
  CIDR aritmetiği kusursuzdu, DQL enjeksiyonu yoktu. **İddia doğruymuş, kanıtı eksikmiş**

**Cursor'ın bıraktığı 5 gerçek hata** (kapılar kurulunca ortaya çıktı, hepsi düzeltildi):
`CategoryRepository::findBy()` üç çağrı noktasında (Doctrine'den miras geliyordu, facade'a
geçişte kayboldu) · `TagRepository::findBy()` sitemap'te · `BlogAttributeHooks` `findMostUsed()`'ın
değişen dönüş şeklini bilmiyordu (Hook izolasyonu yüzünden **sessizce karantinaya alınıyordu** —
popüler etiketler kutusu çalışmayı bırakmış, kimse fark etmemiş). Ayrıca `findByIds()`
eklenirken **vocabulary koruması** kondu: olmasa uydurulmuş bir POST ile etiket kategori
olarak iliştirilebilirdi.

**Ayrıca:** `cp:blog:seed-demo-content` her koşumda ölüyordu (`$localeProvider` hiç enjekte
edilmemişti) · `#[CpResource]` liste şablonu kurulu olmayan bir Twig filtresi (`u`) çağırıyordu ·
README PostgreSQL desteği iddia ediyordu, 60 migration'ın hepsi ham MySQL DDL.

**T3.6 + T5.2a:** `cp:update` ve `cp:doctor` yazıldı (detay yukarıdaki maddelerde).

**⚠️ VERİ KAYBI OLAYI VE ÖNLEMİ:** Bir toplu düzenleme betiğinde ikinci `preg_replace`
derlenemeyip `null` döndürdü ve o `null` dosyalara yazıldı — **14 entegrasyon test dosyası
boşaldı**. Yalnız biri git'te izleniyordu; 13'ü kurtarılamadı ve PHPUnit cache'indeki test
adlarından + bu dosyadaki açıklamalardan **yeniden yazıldı**. Sonuç eskisinden iyi:
49 test / **214 assertion** (öncesi 176), baseline 409 → 366.
**Önlem (`.githooks/pre-commit`, üçü de gerçekten engellediği test edilerek):** boş dosya,
%80'den fazla küçülen dosya, sır benzeri yol. Kurulum: `git config core.hooksPath .githooks`.
Asıl ders şu: **13 dosyanın hiçbiri commit edilmemişti.** Artık her şey git'te (12 commit).

**Doğrulama:** PHPStan L6 temiz · cs-fixer temiz · **966 unit + 55 entegrasyon** yeşil ·
entegrasyon paketi iki ardışık koşumda birebir aynı · `cp:doctor --fail-on=high` gerçek DB'de 0 ·
lint:container dev+prod.

**Kalan risk:** 51 dosyada `strict_types` yok · toplu stil sweep'i (452 dosya) bekliyor ·
migration'lar MySQL'e çivili (satır 40 karar bekliyor) · commit'ler henüz **push edilmedi**
(depo public, onay bekliyor).

### 2026-09-12 (18) — GC2: forum_notifications drop + Category/Tag → Vocabulary
**Amaç:** GC1 sonrası biriken borç — bildirim tek tablo; Blog taksonomisi çekirdek
Vocabulary/Term’e taşınsın (A++ satır 8/20 kapanışı).

**1) Forum bildirim drop:** kalan satırlar `cp_notifications`’a kopyalandı;
`forum_notifications` DROP (`Version20260912150000`); `ForumNotification` entity/repo
silindi; okuma zaten `ForumInboxItem` + `cp_notifications`.

**2) Category/Tag → Term:** vocabs `blog_category` (hiyerarşik) + `blog_tag` (düz);
`Category`/`Tag` entity silindi; `Node` M2M → `Term` (`category_id`/`tag_id` kolonları);
`CategoryRepository`/`TagRepository` Term facade; Blog admin/front/seed/SEO uyarlandı;
Blog installer `VocabularySeeder`.

**Migration sertliği:** ilk `…160000` denemesi in-place `UPDATE node_tag` PK çakışmasıyla
kısmi kaldı (categories/tags düşmüş, terimler yazılmış, version kaydı yok). Idempotent
yeniden yazım: join tablolarını `INSERT IGNORE` rebuild + orphan tag resume
(eski id → blog_tag terim sırası). Yeniden koştu → New=0.

**Doğrulama:** DQL (4 cat / 2 tag; node 14 tags=2; node 29 cat=CMS); HTTP 200
`/tr/blog/kategori/cms-cmf`, `/tr/blog/etiket/cpalius`; `/admin/categories` 302 (auth);
lint:container / lint:twig (categories+tags) / lint:yaml / php -l. phpunit yok.

**Sıradaki:** T3.6 `cp:update`. Kalan GC: webhook→Messenger (bilinçli).

### 2026-09-12 (17) — T3.5: DB log + AACP logs + mail log (A++)
**Yaklaşım:** İki tablo — `cp_log_entries` (Monolog watchdog) + `cp_mail_logs` (giden
mail + resend). AuditLog / SystemTelemetryLog dokunulmadı.

**Adım 0:** Bekleyen 5 migration uygulandı (…230000 …130000); T3.5 `…140000` eklendi.

**Yeni:** `symfony/monolog-bundle`; `DoctrineLogHandler` + terminate flush;
`LoggingSettings`; `AACPLogController` `/aacp/logs` + `/mail` + resend;
`PurgeLogEntriesTask` (`logging.purge`); `CpMailerService` MailLog hooks;
`system.logs.manage`.

**Doğrulama:** migrations New=0; lint:container / lint:yaml / lint:twig. phpunit yok.

**Sıradaki:** (güncellendi) GC2 tamamlandı → T3.6 `cp:update`.

### 2026-09-12 (16) — GC1: T3.2 iptal + A→A++ boşluk kapatma
**Karar:** T3.2 Multisite/org iptal (öncelik dışı). Sıradaki iş yeni Tier değil; birikmiş
A satırlarını A++ yapmak. Sert kural: çekirdek `Modules\` import etmez.

**Cache (13 A→A++):** `OriginCacheVaryContext` (`locale` + `anon|auth`; v1 anon yaz/oku);
disk `_ctx/{locale}/anon/…`; `.htaccess` tercih sırası; `SettingsRegistry::clearCache(...$keys)`
→ `config:*` purge; request touched keys → writer indeks.

**Taksonomi (8 A→A++):** Term formunda `FieldsFormType` + `FieldValuePersister`;
`AACPFieldController` VocabularyRegistry bundle birleşimi; `TaxonomyCapabilityRegistrar`
+ REQUEST sync; term = `taxonomy.manage` **veya** `taxonomy.{vid}.manage`.

**Bildirim (20 A+→A++):** Forum `notify()` → `NotificationDispatcher::dispatch('forum.*')`;
`contributions.yaml` `notification_types` (eski `forum_notif_*` prefs); okuma
`cp_notifications` + `ForumInboxItem` adapter. (`forum_notifications` drop → GC2’de
kapandı.)

**Doğrulama:** `rg "use Modules\\\\" cp-core/src` = boş; lint:container / lint:yaml /
lint:twig (term_form) / php -l. phpunit yok (kullanıcı).

**Kalan (GC1 anında):** Category/Tag migrasyonu; webhook→Messenger; forum tablo drop;
bekleyen migration'lar → GC2’de Category/Tag + forum drop kapandı; webhook hâlâ bilinçli.

### 2026-09-12 (15) — T3.1: Async kuyruk + bildirim primitifi (B → A+)
**Yaklaşım:** Hibrit kuyruk. Messenger Doctrine = mail/bildirim (Symfony failed/retry).
`cp_async_jobs` = webhook (SSRF/imza yolu dokunulmadı). AACP iki kuyruğu ayrı gösterir.

**Yeni:** `symfony/doctrine-messenger`; `SendMailMessage` + handler; `CpMailerService`
enqueue/sendNow ayrımı; `MessengerConsumeTask` cron; `Notification*` paketi (entity,
dispatcher, channels, prefs, digest, editorial hook); `/hesap/bildirimler`;
`/aacp/queue`; `QueueStatusService`; migrations `Version20260912120000` + `…130000`.

**Doğrulama:** lint:container / lint:yaml / php -l (bu oturum). phpunit yok (kullanıcı).

**Kalan (o an):** Forum inbox migrasyonu → GC2’de kapandı; webhook→Messenger bilinçli hibrit.

### 2026-09-12 (14) — TS: Güvenlik sertleştirme katmanı (A → A++), T3.3 de kapandı
**Yaklaşım:** Hedef A+ değil A++. Ayırt edici iddia şu: Drupal'da seckit + perimeter +
flood_control + password_policy + tfa + key olarak altı ayrı contrib, WordPress'te ücretli
eklenti olan ne varsa **çekirdekte, varsayılan açık ve tek ekrandan yönetilebilir**.
Üç katman: çevre, kimlik, denetim. Tamamı `#[CpSetting]` ile 47 ayar — hiçbiri kod
değişikliği istemiyor. Perimeter kodunun tamamı try/catch: sertleştirme bozulur, 500 vermez.

**Yeni (çekirdek `cp-core/src/Core/Security/`):**
- Çevre: `Http/CspNonceProvider`, `Http/SecurityHeaderPolicy`, `EventListener/SecurityHeadersSubscriber`,
  `Twig/SecurityHeaderExtension`, `Controller/CspReportController`,
  `EventListener/RequestGuardSubscriber` (`BannedIpSubscriber`'ın yerine),
  `Service/IpMatcher`, `Service/IpBanService` (v2), `Service/SecurityEventRecorder`
- Kimlik: `Flood/FloodService`, `Service/LoginDefenseService`, `EventListener/LoginDefenseSubscriber`,
  `Password/{BreachChecker,PasswordHistory,PasswordPolicy,PasswordChanger}`,
  `TwoFactor/{TotpGenerator,TwoFactorService,TwoFactorSession,TwoFactorGuardSubscriber}`,
  `Session/{SessionRegistry,SessionGuardSubscriber}`
- Denetim: `Settings/SettingSecretCodec`, `Audit/{SecurityFinding,SecurityAuditor}`,
  `Command/SecurityAuditCommand`, `Task/SecurityMaintenanceTask`
- Arayüz: `Controller/Admin/AACPSecurityCenterController` + `templates/aacp/security/index.html.twig`,
  `Controller/TwoFactorController` + 3 hesap şablonu, `Settings/Definitions/HardeningSettings` (47 ayar)
- `Version20260912100000`: ban kolonları (`expires_at`/`reason`/`source`/`is_range`/`hit_count`) +
  `cp_user_sessions` + `cp_password_history`

**Değişen:** WAF analizi `TERMINATE` → `REQUEST` (artık engelleyebiliyor, verdict Request'te
saklanıp `TelemetrySubscriber` ikinci taramayı atlıyor); `/aacp` ban muafiyeti kaldırıldı
(`/aacp/recovery` muaf kaldı); beş parola yazım noktası `PasswordChanger`'a alındı;
`SettingsRegistry`/`SystemSettingsService`/`AACPController` sır şifrelemesine bağlandı;
`framework.yaml` trusted proxy (trusted_hosts **bilinçli olarak** ayara taşındı — boot
sırasında çözülmeyen bir env değişkeni siteyi geri dönüşsüz düşürüyordu).

**Doğrulama:** `php -l` 483 dosya 0 hata · `lint:yaml` 90 dosya OK · `lint:container` OK ·
`lint:twig` yalnız bilinen pre-existing `aacp/resources/index.html.twig` hatası ·
`debug:router` 10 yeni rota kayıtlı · `cp:security:audit` canlı DB'de uçtan uca çalıştı
(60/100, 0 kritik) · EN/TR 210 anahtar tam parite. phpunit koşulmadı (kullanıcı kararı).

**Kalan risk:** `Version20260912100000` dâhil üç migration uygulanmadı — uygulanana kadar
oturum kaydı ve parola geçmişi yazamaz (ikisi de best-effort, istek düşmüyor). Sudo/re-auth
modu ve WebAuthn yok. Varsayılanlar bilinçli olarak ölçülü (CSP `report`, WAF `detect`):
denetim ekranı operatörü sıkılaştırmaya yönlendiriyor, katman kendi kendine sıkılaştırmıyor.

### 2026-09-11 (13) — T2.5: İsimli text format + filtre pipeline (C → A+)
**Yaklaşım:** Drupal Filter API yalnızca çıktıda çalışır — DB bir XSS deposu, bir filtre
kapanınca eski markup canlanır; CKEditor toolbar ile allowlist kayar. CPalius kayıtta
allowlist + çıktıda dönüşüm + hiçbir formatın açamadığı deny-list. Token/markdown çekirdekte
(Drupal'da contrib). Yetkisi düşen format yazımda reddedilir.

**Yeni (çekirdek `cp-core/src/Core/TextFormat/`):**
- `text_formats.yaml` + `TextFormatRegistrationPass` + `TextFormatRegistry` / `ResolvedTextFormat`
- Filtreler: `HtmlRestrictFilter`, `MarkdownFilter` (ham HTML yok), `TokenFilter` (HTML-escape),
  `AutoLinkFilter`, `MediaEmbedFilter` (YouTube/Vimeo, oEmbed ağı yok), `NewlineToBrFilter`
- `TextFormatProcessor` (storage / output faz + failsafe + embed en son)
- `TextFormat` entity + `cp_text_formats` (`Version20260911240000`) + seeder + config provider
- AACP `/aacp/text-formats`, Twig `|text_format`
- RichText field `{value, format}` + format seçici; CBAC `text_format.{id}.use`

**Doğrulama:** bu oturumda phpunit koşulmadı (kullanıcı kararı: toplu test tüm geliştirme bitince).
Migration `doctrine:migrations:migrate` ile uygulanmalı.

**Kalan:** Forum/bio hâlâ `RichTextSanitizer`; Jodit etiket-etiket kilit değil; oEmbed T4.3.

### 2026-09-11 (12) — T2.4 kapatıldı: Token + desen-bazlı path alias (C → A+)
Önceki oturumda motor yazılmıştı (444 test yeşil). Bu oturum A+ boşluklarını kapattı:
varsayılan kalıp seed (`/blog/[node:created:Y]/[node:title]`), `TokenContext` (yazar=`user`),
HTML-escape'li replace, gerçek token'lı doğrulama maili, site slogan/url token'ları, SEO
listing fallback'i tokenize, `PathAliasPatternSeeder` + CMI YAML.

**Kanıt:** yeni post kaydı `PathAliasAutoGenerateListener` → `blog/2026/baslik`; mail
`[user:display_name]` / `[site:name]` çeviri katalogunda gerçekten var.

**Kalan:** Term otomatik alias; Drupal zincir sözdizimi bilinçli yok.

### 2026-09-11 (11) — T2.3: Cache tag/context primitifi (B → A hedefi)
**Yaklaşım:** T1.4'ün (satır-bazlı erişim) ve T1.5'in (entity olayları) "rebuild yok, otomatik"
felsefesini cache invalidation'a taşımak. Drupal'ın cache tag sistemi olgun ama CPalius'un
kendi T1.5 event altyapısı zaten var — onun üstüne bindirmek, yeni bir event bus icat etmeden
aynı kazanımı (entity kaydı → otomatik, isabetli purge) verdi.

**Yeni:**
- `cp-core/src/Core/OriginCache/CacheTag.php` — tag sözleşimi (`node:42`, `list:node:post`, `config:blog.settings`)
- `cp-core/src/Core/OriginCache/CacheTagCollector.php` — request-scoped, controller "bu sayfa şuna bağlı" der (`addEntityTag`/`addListTag`/`setMaxAge`)
- `cp-core/src/Core/OriginCache/CacheTagIndex.php` — tag→path ters-indeksi, `cache.fragments` pool'unda (ilk gerçek tüketicisi — daha önce çekirdekte hiç kullanılmıyordu)
- `cp-core/src/Core/OriginCache/EntityCacheTagInvalidator.php` — `#[CpHook]`, `entity.any.post_{insert,update,delete}`, T1.5 event'inden tag çıkarıp `OriginCachePurger::purgeTags()` çağırır
- Testler: `CacheTagTest` (3), `CacheTagCollectorTest` (3), `CacheTagIndexTest` (4), `OriginCachePurgerTagTest` (3), `OriginCacheStoreTtlOverrideTest` (4), `EntityCacheTagInvalidatorTest` (5) — hepsi `cp-core/tests/Unit/Core/OriginCache/`, gerçek `OriginCacheStore`/`CacheTagIndex` nesneleriyle (ikisi de `final`, mock yerine temp-dir/ArrayAdapter kullanıldı — `OriginCachePolicyTest`'in kurduğu convention)

**Değişen:**
- `cp-core/src/Core/OriginCache/OriginCachePurger.php` — yeni `purgeTags(string ...$tags): int`, ctor'a `CacheTagIndex`
- `cp-core/src/Core/OriginCache/OriginCacheStore.php` — `putHtml()`'e opsiyonel `$ttlOverride` (per-response max-age, `.ttl` yan dosyası), `deleteExact()` sidecar'ı da temizliyor
- `cp-core/src/Core/OriginCache/OriginCacheWriter.php` — ctor'a `CacheTagCollector`+`CacheTagIndex`; `onKernelResponse()` `X-Cache-Tags` başlığını yazıyor, `onKernelTerminate()` tag'leri indexliyor + maxAge override'ı `putHtml()`'e geçiriyor
- `cp-content/modules/Blog/Controller/PostFrontController.php` — `CacheTagCollector` enjekte edildi; `show()` → `node:{id}`, `index()/category()/tag()/archiveMonth()` → `list:node:post` (+ `category()` ayrıca `category:{id}`)
- `cp-core/config/packages/cache.yaml` — **bug düzeltmesi:** `when@dev`/`when@test`'te `cache.fragments` için `provider: ~` eklendi (aşağıda)

**Yan keşif/düzeltmeler (bizden değil, ama T2.3 ilk kez tetikledi):**
1. `cache.fragments` pool'u çekirdekte hiç kullanılmadığı için `provider: '%env(MEMCACHED_DSN)%'`'nin `when@dev`/`when@test`'te temizlenmediği fark edilmemişti — `CacheTagIndex` onu gerçekten istediği an `AbstractAdapter::createConnection()` DSN'i görüp ArrayAdapter'ın altında gerçek `\Memcached`'e bağlanmaya çalıştı ("Memcached > 3.1.5 is required" — Laragon'da memcached kapalı). `provider: ~` ile düzeltildi, hem dev hem test bloğunda.
2. İki dosyada (`ViewModeRegistrationPass.php` — T2.2'den, `EntityAccessGrant.php` — T1.4'ten) `<?php`'ten önce fazladan bir boşluk karakteri vardı → `declare(strict_types=1)` "very first statement" kuralını ihlal edip fatal error veriyordu, kernel boot'u tamamen kırıyordu (composer'ın `post-install-cmd` → `cache:clear` adımı bu yüzden başarısız oluyordu). Tek karakterlik düzeltme, ikisi de untracked/uncommitted dosyalardı.
3. Bu makinede `vendor/` hiç kurulmamıştı (`composer.json`'daki `vendor-dir: cp-includes/vendor`) — `composer install` ile kuruldu, mevcut lock dosyası değişmedi.

**Doğrulama:** `phpunit` tam paket **409/yeşil**, 0 hata, 3 pre-existing deprecation (T1.1'den beri bilinen) · `lint:container --env=dev` ve `--env=prod` ikisi de OK · `lint:yaml --parse-tags` 86 dosya OK · `phpstan` (level 0, değişen dosyalar) temiz · migration yok (yeni tablo yok, mevcut `cache.fragments` pool'u kullanıldı).

**Kanıt:** `EntityCacheTagInvalidatorTest::testPostUpdatePurgesTheNodesOwnPageAndBothOfItsListingPages` — gerçek `OriginCacheStore` (temp dir) + `CacheTagIndex` (ArrayAdapter) ile: `/blog/post-42` (`node:42`), `/blog` (`list:node`), `/blog/kategori/haber` (`list:node:post`) ve ilgisiz `/forum` önceden diskte; node 42 için `post_update` event'i tetiklenince ilk üçü düşüyor, `/forum` dokunulmadan kalıyor.

**Kalan/not:** context-vary, `config:*` tag bağlama, Forum/Pages/Menu/Roadmap migrasyonu ve gerçek ESI bilinçli olarak ertelendi — gerekçeler §2 "alan alan" notunda (satır 13) ve §3 T2.3 KALAN maddelerinde.

### 2026-09-11 (10) — Ölçekleme notasyonu: sembol → harf notu
**Kullanıcı kararı:** §2 skor kartındaki beş kademeli sembol seti (`⬜/▪/◐/●/★`), daha kolay
okunur olması için okul notu harflerine çevrildi: `D`=yok, `C`=ilkel/parça, `B`=kısmi,
`A`=olgun, `A+`=sınıfının en iyisi (birebir eşleme, sıralama değişmedi). Skor tablosu, "alan
alan" notlarındaki geçiş oku (`C → B` gibi), "HEDEF KRİTERİ" bloğu ve tüm ilerleme günlüğü
satırları güncellendi. Kod değişikliği yok, sadece bu dosya.

### 2026-09-11 (9) — T2.2: View / display modes (A+ hedefi)
**Kapsam süzgeci uygulandı:** T2.1 iptalinden hemen sonra gelen ilk madde. "Sadece web
sitesi için mi, yoksa her uygulamaya mı hizmet eder" testinden geçirildi: view mode + display
config, bir CRM kaydının liste/detay/API çıktısında da işe yarar → kapsamda kaldı. Ama
"tema şablon suggestion cascade" (otomatik `.twig` dosya adı tahmini) web-sitesi temalamasına
özgü bulundu → **bilinçli olarak eklenmedi**, sadece veri modeli + renderer/serializer
entegrasyonu + AACP UI + config provider yapıldı.

**Yeni:**
- `cp-core/config/view_modes.yaml` + `cp-core/src/Core/Display/ViewModeRegistry.php` +
  `DependencyInjection/Compiler/ViewModeRegistrationPass.php` (`CapabilityRegistrationPass` deseni)
- `cp-core/src/Core/Display/Entity/EntityDisplay.php` + `Repository/EntityDisplayRepository.php`
  + migration `Version20260911200000` (`cp_entity_displays`, dev DB'de çalıştı)
- `cp-core/src/Core/Display/EntityDisplayRegistry.php` + `ResolvedDisplayField.php` (cached, `FieldDefinitionRegistry` deseni)
- `cp-core/src/Core/Config/Provider/EntityDisplayConfigProvider.php` (`display.{bundle}`, authoritative)
- `cp-core/src/Controller/Admin/AACPDisplayController.php` + `templates/aacp/display/{index,bundle}.html.twig`
- Testler: `ViewModeRegistryTest` (3), `EntityDisplayRegistryTest` (3, unit) — `cp-core/tests/Unit/Core/Display/`;
  `EntityDisplayTest` (3, integration) — `cp-core/tests/Integration/`

**Değişen:**
- `cp-core/src/Kernel.php` — `ViewModeRegistrationPass` kaydı
- `cp-core/config/packages/doctrine.yaml` — `CpDisplay` mapping
- `cp-core/config/services.yaml` — `EntityDisplayRegistry` ($cache)
- `cp-core/src/Core/Field/Display/FieldRenderer.php` — yeni ctor bağımlılığı `EntityDisplayRegistry`, yeni `renderEntity(entity, viewMode)` metodu
- `cp-core/src/Core/Field/Api/FieldValueSerializer.php` — `serialize()` artık `$viewMode` alıyor (varsayılan 'default' = eski davranış), `FieldDefinitionRegistry` bağımlılığı `EntityDisplayRegistry`'ye devredildi
- `cp-core/src/Core/Field/Twig/FieldRuntime.php` + `FieldExtension.php` — `cp_entity_view()` Twig fonksiyonu
- `cp-core/tests/Unit/Core/Field/FieldTestTrait.php` + `FieldRendererTest.php` — `entityDisplayRegistry()` yardımcı

**Doğrulama:** `phpunit` **388/yeşil** (340 unit + 48 integration, sıfır regresyon) ·
`lint:container` dev+prod · `lint:yaml --parse-tags` 86 · `lint:twig` yeni 2 dosya ·
`phpstan` temiz · `schema:validate` mapping OK · migration dev DB'de çalıştı.

**A+ kanıtı (Drupal'ın Manage Display'inin "tema-only" zaafı):** `EntityDisplayTest` — bir alanı
`teaser` view mode'unda gizle → HEM `FieldRenderer::renderEntity()` (tema) HEM
`FieldValueSerializer::serialize()` (API) aynı config'i okuyor, ikisinde de tutarlı şekilde
kayboluyor. Drupal'da JSON:API display config'i görmezden gelir — iki ayrı yerde tanımlarsın.

**Kalan / not:** Çalışma zamanında özel view mode oluşturma UI'ı yok (YAML/modül ile
eklenir); formatter ayarları view mode başına override edilemiyor (v1: sadece visible/
weight/label); tema suggestion cascade bilinçli olarak yok.

### 2026-09-11 (8) — T2.1 (Views-lite / site-builder) kullanıcı kararıyla İPTAL
Kod değişikliği yok — kapsam kararı. Kullanıcı: CPalius yalnızca web sitesi değil, CRM/ERP/
hosting paneli/dosya paylaşım gibi çok farklı uygulama tipleri için çekirdek; "site builder"
(kodsuz sayfa/liste/blok kurma UI'ı) web-sitesi-merkezli bir CMS özelliği, çekirdeğe gereksiz
yük bindirir ve amacından saptırır. Karar: **T2.1 tamamen iptal**, karşılık gelen kıyas
satırı ("Views / kodsuz listeleme") skor tablosundan kaldırıldı.

**Değişen (sadece `CPALIUS_YOL_HARITASI.md`):**
- Üstteki amaç bloğuna kalıcı "KAPSAM SINIRI" notu eklendi — bundan sonraki her tier
  maddesi "sadece web sitesi için mi, yoksa genel çekirdek yeteneği mi" sorusundan geçecek.
- §2 skor tablosu: satır 11 "KALDIRILDI" olarak işaretlendi (numaralandırma kaydırılmadı —
  diğer madde referansları bozulmasın diye 11 bilinçli boş bırakıldı), ilgili "alan alan"
  notu ve "NEREDE GERİDE" listesindeki referansı silindi.
- TIER 2 başlığı "Site-builder / entegratör gücü" → "Entegratör gücü" (site-builder kelimesi
  çıkarıldı; kalan TIER 2 maddeleri — view modes, cache tags, token/alias, text formats —
  geliştirici/entegratör araçları, son-kullanıcı site-builder UI'ı değil, kapsamda kalıyorlar).
- T2.1 bloğu iptal gerekçesiyle işaretli tutuldu (sildirmedim, gelecekte "neden yok" sorusuna
  cevap olsun diye) — `CpEntityQuery` (T1.2) kendisi İPTAL DEĞİL, hâlâ geçerli; iptal edilen
  sadece onun üstüne kurulacak site-builder UI'ıydı.

**Sıradaki:** T2.2 — View modes + display modes + theme suggestion.

### 2026-09-11 (7) — T1.5: Tipli entity olayları — TIER 1 TAMAMLANDI
**Mimari karar:** Paralel bir event bus (Symfony EventDispatcher vb.) icat etmek yerine,
tipli olaylar MEVCUT Hook motoruna bindirildi (`HookContext::get('event')`). Gerekçe:
manifesto/agent kuralı "no extra abstraction" + mevcut `HookManager` zaten per-listener
izolasyon sağlıyor (attribute VE flat-file hook'lar için). Bu tercih WP'nin (ilk taramada
bu satıya yanlışlıkla A+ verilmişti — düzeltildi, bkz. §2) en büyük zaafını çözüyor: bir
listener'ın kendi hatası artık isteği çökertmiyor.

**Yeni:**
- `cp-core/src/Core/Entity/Event/` — `EntityLifecycleEventInterface`, `AbstractEntityLifecycleEvent`,
  `RejectableEventTrait`, `EntityPreSaveEvent`, `EntityPostInsertEvent`, `EntityPostUpdateEvent`,
  `EntityPreDeleteEvent`, `EntityPostDeleteEvent`, `EntityAccessEvent`, `EntityLifecycleRejectedException`
- `cp-core/src/Core/Entity/EventListener/EntityLifecycleListener.php` — tek, entity-agnostik Doctrine köprüsü
- `cp-core/tests/Unit/Core/Entity/EntityLifecycleListenerTest.php` (6, sahte `HookDispatcherInterface`)
- `cp-core/tests/Integration/EntityLifecycleHookIntegrationTest.php` (4) + yeni fixture modül
  `cp-core/tests/Fixtures/Modules/HookFixture/` (`HookFixtureModule`, `EntityHookRecorder` —
  gerçek `#[CpHook]` servisi, `ModuleIsolationTest`'in `IsolationTestKernel` deseniyle)

**Değişen:**
- `cp-core/src/Core/Security/Voter/CPaliusVoter.php` — **T1.4 tutarlılık düzeltmesi**:
  yeni ctor bağımlılıkları (`EntityAccessManager`, `ResourceRegistry`, `HookDispatcherInterface`);
  `supports()` bare/`.own`/`.any` ailesini tanıyor; `voteOnAttribute()` role-capability
  sonuçsuz kalırsa per-record grant'e, o da sonuçsuzsa `EntityAccessEvent`'e düşüyor —
  mevcut `.own`/`.any` davranışı **hiç değişmedi** (aynı dal, aynı sıra)

**Doğrulama:** `phpunit` tam paket **379/yeşil** (334 unit + 45 integration — hiçbir mevcut
test kırılmadı, Blog/Pages/Forum/Studio dahil) · `lint:container` dev+prod (bir ara stale
cache yüzünden yanlış pozitif verdi, `cache:clear` sonrası düzeldi) · `phpstan` temiz.

**Test sürecinde öğrenilen gerçek Doctrine davranışı:** `prePersist` `flush()`'ta değil,
**`persist()` çağrısının içinde senkron** tetikleniyor (Doctrine cascade-persist için buna
ihtiyaç duyuyor); `preUpdate` ise gerçekten `flush()`'ta. Testi buna göre düzelttim.

**Kalan / not:** `PostUpdateEvent`'e Doctrine changeset (`getEntityChangeSet()`) taşımak
bilinçli olarak ertelendi (nice-to-have, T1.5'in kabul kriterlerinde yoktu). Blog/Forum'un
kendi ad-hoc Doctrine listener'larının bu olaylara taşınması opsiyonel, ayrı iş.

**TIER 1 (T1.1–T1.5) burada tamamlandı.** Skor kartında yeni A+'lar: satır 4 (B, tam A+ değil —
bilinçli), 6 (B), 7 (A+), 8 (A), 10 (A+). Sıradaki: **TIER 2, T2.1 — Views-lite.**

### 2026-09-11 (6) — T1.4: Satır-bazlı erişim grantları (A+ hedefi)
**Yeni standart:** Kullanıcı bu oturumda kriteri netleştirdi — her satırda hedef artık
sadece yetişmek değil, **o satırın `A+`ı olmak**. §2 başına "HEDEF KRİTERİ" notu eklendi.
T1.4 bu kritere göre tasarlandı: önce Drupal `node_access`'in 4 bilinen zaafı çıkarıldı
(realm/gid ayrılığı, pahalı rebuild, Node-only, doğrulamasız hook), sonra her biri için
somut çözüm kondu.

**Yeni:**
- `cp-core/src/Core/Security/Entity/EntityAccessGrant.php` + `Repository/EntityAccessGrantRepository.php`
- `cp-core/src/Core/Security/EntityAccessManager.php` — grant/revoke/isGrantedForRecord/buildScopeCondition
- `cp-core/migrations/Version20260911180000.php` (`cp_entity_access_grants`, dev DB'de çalıştı)
- `cp-core/tests/Integration/EntityAccessGrantTest.php` (4)

**Değişen:**
- `cp-core/src/Core/Security/QueryScopeApplier.php` — 5. parametre `$entityType='node'`
  (geriye uyumlu); `.any` hâlâ hızlı yol, `.own` + grant EXISTS `OR` ile birleşiyor

**Doğrulama:** `phpunit` tam paket **369/yeşil** (328 unit + 41 integration — Blog/Pages/
Studio dashboard'un 6 `QueryScopeApplier` çağrı noktası dahil hiç kırılmadı) · `lint:container`
dev+prod · `schema:validate` mapping OK · `phpstan` temiz.

**Önemli düzeltme (test sürecinde bulundu):** İlk yazımda `grant()` bilinmeyen capability'yi
reddederken bare `node.post.view`'i de reddediyordu çünkü sadece `.own`/`.any` suffix'li
haller `capabilities.yaml`'da kayıtlı — CapabilityRegistry'de bare hâl hiç yok. Düzeltme:
bir capability ailesi, `.own` VEYA `.any` hâllerinden biri kayıtlıysa "bilinen" sayılır.

**Kalan / not:** Tarayıcıdan grant yönetim UI'ı yok (Drupal çekirdeğinde de yok). Hard-delete
eden çağıranın `revokeAllForEntity()` çağırması sorumluluğu (gerçek FK yok, polymorphic).
`hook_entity_access` benzeri tipli olay ihtiyacı T1.5'e taşındı.

### 2026-09-11 (5) — T1.3 tamamlandı: AACP taksonomi UI + config provider
**Yeni:**
- `cp-core/src/Controller/Admin/AACPTaxonomyController.php` — vocabulary CRUD + term CRUD (hiyerarşi, locale, çeviri grupları), `AACPFieldController`/Blog `CategoryAdminController` deseni (raw request, plain Twig, POST-redirect-GET, iki CSRF token: `aacp_taxonomy_vocabulary` / `aacp_taxonomy_term`)
- `cp-core/templates/aacp/taxonomy/{index,vocabulary_form,terms,term_form}.html.twig` — `twig:cp:card/table/translation_tabs/locale_filter` bileşenleri; `terms.html.twig` özyinelemeli `{% macro branch %}` ile ağaç
- `cp-core/src/Core/Config/Provider/TaxonomyConfigProvider.php` — `taxonomy.{machine_name}`, **upsert-only** (silmez — CASCADE terim/içerik kaybı riski)
- `cp-core/src/Core/Taxonomy/VocabularySeeder.php` — modül installer'ları için (`FieldDefinitionSeeder` deseni)
- `cp-core/tests/Integration/AacpTaxonomyControllerTest.php` (3)

**Değişen:** `cp-core/config/services.yaml` (`VocabularySeeder: public`) · çeviriler (`aacp.menu.taxonomy`, `aacp.taxonomy.*` ~50 anahtar EN+TR)

**Doğrulama:** `phpunit` unit 328/yeşil, integration doğrulanıyor (bkz. altta) · `lint:container` dev+prod · `lint:yaml --parse-tags` 85 · `lint:twig` 4 yeni dosya yeşil · `phpstan` temiz · `debug:router` 8 route doğru.

**Kalan / not:** Term formunda özel alan girişi YOK (desen T1.1'de kanıtlandı — `UserType`
gibi `FieldableFormBuilder` eklemek ucuz bir fast-follow). Per-vocab dinamik yetki yok (tek
`taxonomy.manage`). Vocabulary silme, içinde terim varsa engelleniyor (CASCADE koruması).

### 2026-09-11 (4) — T1.3a: Vocabulary + Term (çekirdek)
**Yeni:**
- `cp-core/src/Core/Taxonomy/Entity/{Vocabulary,Term}.php`
- `cp-core/src/Core/Taxonomy/Repository/{Vocabulary,Term}Repository.php`
- `cp-core/src/Core/Taxonomy/VocabularyRegistry.php`
- `cp-core/migrations/Version20260911120000.php` (`cp_vocabularies` + `cp_terms`)
- `cp-core/tests/Integration/TaxonomyTest.php` (2)

**Değişen:**
- `cp-core/src/Core/Field/ReferenceTargetResolver.php` — `term`/`term:{vid}` hedefleri; ctor +`VocabularyRegistry` (dönüş şekli `{class,nodeType,termVocabulary}`)
- `cp-core/config/packages/doctrine.yaml` — `CpTaxonomy` mapping
- `cp-core/config/services.yaml` — `VocabularyRegistry` ($cache: '@cache.app')
- `cp-core/config/capabilities.yaml` — `taxonomy.manage`
- `cp-content/translations/*` — `entity.type.taxonomy_term`
- `cp-core/tests/Unit/Core/Field/FieldTestTrait.php` + `EntityReferenceFieldTypeTest.php` — `vocabularyRegistry()` helper

**Doğrulama:** `phpunit` 362/yeşil (328 unit + 34 integration) · `lint:container` dev+prod · `lint:yaml --parse-tags` 85 · `schema:validate` mapping OK · `phpstan` temiz · migration dev DB'de çalıştı.

**Kalan / not:** `FieldDefinition`'da `entity_type` kolonu YOK → vocabulary machine_name'i ile node type'ları aynı `bundle` isim uzayını paylaşıyor (çakışma riski düşük, ihtiyaç olunca kolon eklenir). Flat index Term'i kapsamıyor. (AACP UI + config provider + per-vocab yetki + Category/Tag migrasyonu sonraki dilimlerde kapandı — GC1/GC2.)

### 2026-09-11 (3) — T1.2: CpEntityQuery
**Yeni:**
- `cp-core/src/Core/Entity/Query/CpEntityQuery.php` — akıcı sorgu nesnesi (servis değil)
- `cp-core/src/Core/Entity/Query/CpEntityQueryFactory.php` — servis (public); `forBundle/forNode/forEntityType/fromSelector`
- `cp-core/tests/Integration/CpEntityQueryTest.php` (4 test)

**Değişen:**
- `cp-core/config/services.yaml` — `CpEntityQueryFactory: { public: true }` (henüz tüketici yok)
- `cp-core/src/Repository/NodeRepository.php` — `findNodesBySelector()` `@deprecated`

**Doğrulama:** `phpunit` 360/yeşil · `lint:container` dev+prod yeşil · `phpstan` (Query dizini) temiz · N+1 guard test env'de aktif, tek sorgu.

**Kalan / not:**
- v1 Node-only. Kolon whitelist + flat-index sadece Node. `forEntityType()` başka id'yi reddediyor.
- OR/AND grupları tek seviye (iç içe değil). `whereField` grup içinde yasak (join tüm sonucu filtrelerdi).
- Alan-bazlı sort, ilişki join gezinme, aggregation yok → ihtiyaç çıktıkça sonraki iterasyonda eklenir (not: bu ihtiyacı doğuracak "Views-lite" T2.1 kullanıcı kararıyla iptal edildi, bkz. TIER 2 bölümü).
- `NodeFieldIndex` genelleştirmesi (entity_type kolonu / generic index) T1.3'e bırakıldı.

### 2026-09-11 (2) — T1.1 tamamlandı: AACP kullanıcı formu Field API entegrasyonu
**Değişen:**
- `cp-core/src/Form/UserType.php` — `FieldableFormBuilder` enjeksiyonu, `fields` çocuğu (bundle 'user'), `field_locale` opsiyonu (varsayılan 'und')
- `cp-core/src/Controller/Admin/AACPUserController.php` — `FieldValuePersister` + `FieldDefinitionRegistry` enjeksiyonu; `createUserForm(...,Request)` `field_locale` geçiyor; yeni `currentUserFieldValues()` + `persistUserFields()`; `create`/`edit` akışlarına bağlandı (düzenlemede GET ön-doldurma)
- `cp-core/templates/aacp/users/form.html.twig` — koşullu "Özel alanlar" kartı
- `cp-content/translations/messages+intl-icu.{en,tr}.yaml` — `aacp.users.form.section_custom_fields`

**Yeni:** `cp-core/tests/Integration/UserFieldableFormTest.php`

**Doğrulama:** `phpunit` 356/yeşil · `lint:container` dev+prod yeşil · `lint:twig` aacp/users yeşil · `lint:yaml` yeşil · `phpstan` (level 0) temiz.

**Not:** `FieldableFormBuilder::add()` bundle'da alan yoksa no-op → `user` alanı tanımlanana kadar sıfır etki. Ön yüz `AccountProfileType` bilinçli ertelendi (ayrı tema yüzeyi).

### 2026-09-11 (1) — Oturum: T1.1 çekirdek dilimi
**Yeni dosyalar:**
- `cp-core/src/Core/Entity/FieldableInterface.php`
- `cp-core/src/Core/Entity/Attribute/CpEntityType.php`
- `cp-core/src/Core/Entity/EntityTypeDefinition.php`
- `cp-core/src/Core/Entity/EntityTypeRegistry.php`
- `cp-core/src/Core/Entity/DependencyInjection/Compiler/EntityTypeRegistrationPass.php`
- `cp-core/tests/Unit/Core/Entity/EntityTypeRegistryTest.php`
- `cp-core/tests/Unit/Core/Entity/FieldableEntityContractTest.php`
- `cp-core/tests/Integration/EntityTypeRegistrationTest.php`

**Değişen dosyalar:**
- `cp-core/src/Kernel.php` — `EntityTypeRegistrationPass` kaydı (FieldTypeRegistrationPass'ten önce)
- `cp-core/config/services.yaml` — `EntityTypeRegistry` ($rawDefinitions: [])
- `cp-core/src/Entity/Node.php` — `#[CpEntityType]` + `FieldableInterface` (yeni 5 metot, `data`/`type`/`locale`'e delege)
- `cp-core/src/Entity/User.php` — `#[CpEntityType]` + `FieldableInterface`
- `cp-core/src/Core/Field/FieldContext.php` — `?Node` → `?FieldableInterface`
- `cp-core/src/Core/Field/{FieldValueNormalizer,FieldValidator,FieldValuePersister}.php`
- `cp-core/src/Core/Field/Display/FieldRenderer.php`
- `cp-core/src/Core/Field/Api/FieldValueSerializer.php`
- `cp-core/src/Core/Field/Twig/FieldRuntime.php`
- `cp-core/src/Controller/Admin/AACPFieldController.php` — `EntityTypeRegistry` enjeksiyonu + `knownBundles()`
- `cp-content/translations/messages+intl-icu.{en,tr}.yaml` — `entity.type.*`

**Doğrulama:**
- `phpunit` tam paket: **355/yeşil** (346 + 9 yeni), 3 pre-existing deprecation
- `lint:container` dev + prod: yeşil
- `lint:yaml`: 85 dosya yeşil
- `doctrine:schema:validate --skip-sync`: mapping OK
- `phpstan` (level 0): değişen dosyalarda hata yok
- `lint:twig`: 1 hata var ama bizden değil (`aacp/resources/index.html.twig`, untracked)

**Kalan risk / not:**
- Field API'nin hiçbir modül tüketicisi yoktu → kuplaj değişimi düşük riskli oldu.
  Pages/Blog Field API kullanmıyor (A5 migrasyonu hâlâ opsiyonel).
- Flat index (`NodeFieldIndex` + `NodeIndexListener` + `QueryableFieldsRegistry`) hâlâ
  Node'a özel — genelleştirme T1.2 kapsamında (`CpEntityQuery`).
- `cpalius-cmf_test` DB'si bu makinede yoktu, oluşturuldu.

### 2026-09-10 — Oturum: Yol haritası + tam inceleme
- Tüm çekirdek incelendi (Kernel, modül/hook/cron/API/webhook/queue/field/revision/
  moderation/workflow/CMI/security/tema/portal + 8 modül).
- Drupal/TYPO3/WP/ProcessWire kıyas analizi çıkarıldı.
- Bu dosya oluşturuldu. Karar: **Tier 1 tamamen bitirilecek**, sonra sırayla diğerleri.
- Sıradaki: T1.1 — `FieldableInterface` + entity-agnostik Field API.
- Risk/not: T1.1 Field API'de geniş dokunuş; regresyon kalkanı = mevcut ~346 test + Pages/Blog
  el kontrolü. Davranış değişmemeli.
