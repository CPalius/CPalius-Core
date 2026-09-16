# Showcase (Vitrin)

Çok amaçlı vitrin modülü. Üyeler ürünlerini — yazılım, web sitesi, araç, hizmet,
gayrimenkul, aklınıza ne gelirse — yayınlar; **ne yayınlandığını modül değil, site
sahibi tanımlar.**

Modül, CPalius'un ilk ZIP paketi olarak tasarlandı: AACP → Modüller ekranından
yüklenip tek adımda etkinleştirilebilir.

---

## Neden "çok amaçlı"?

Vitrinin merkezinde **Vitrin Türü** (`ShowcaseType`) var. Her tür bir *bundle*'dır
ve kendi alan şemasını taşır:

| Tür (makine adı) | Field API paketi | Alanları |
|---|---|---|
| `vehicle` | `showcase_vehicle` | marka, model, yıl, km, yakıt, vites… |
| `website` | `showcase_website` | alan adı, aylık ziyaretçi, gelir, gelir modeli… |
| `saas` | `showcase_saas` | sizin tanımladıklarınız |

Alanlar çekirdek **Field API** üzerinde çalışır (`cp_field_definitions`), yani
doğrulama, çeviri, biçimlendirme ve görüntüleme davranışı diğer içerik türleriyle
birebir aynıdır. Modülde tek bir "araç alanı" veya "yazılım alanı" sabitlenmiş
değildir; `Resources/../ShowcasePresetLibrary` yalnızca **başlangıç şablonları**
sunar ve oluşturduğu alanlar sonradan serbestçe düzenlenir.

Değerler `cp_showcase_items.data` (JSON) içinde durur. "Filtrelenebilir" işaretli
her alan `cp_showcase_item_index` düz dizinine yazılır (Manifesto Yasa 6.3), ve
listeleme filtreleri bu dizine JOIN atar — JSON taraması yapılmaz. Filtre
kutuları da aynı tanımlardan üretilir: araç listesinde "vites", yazılım listesinde
"lisans" çıkar, şablonda tek satır fark yoktur.

---

## Kurulum

### AACP panelinden (ZIP)

1. `dist/` altındaki `Showcase-1.0.0.zip` dosyasını indirin.
2. **AACP → Modüller → Modül yükle**: ZIP'i seçin, *Etkinleştir* kutusunu işaretleyin.
3. Yükleme sırasında paket sözleşmesi doğrulanır, ardından `lint:yaml` +
   `lint:container` kuru çalıştırması yapılır (Yasa 2.2). Geçerse modül
   `active_modules.php` dosyasına yazılır ve kurulum kancası çalışır.

> Modül dizini zaten varsa yükleme formundaki **Üzerine yaz** kutusunu işaretleyin.

### Konsoldan

```
php cp-core/bin/console cp:module:activate Showcase
```

### Kurulum neyi değiştirir?

| Ne | Nerede | Geri alınır mı? |
|---|---|---|
| 8 tablo (`cp_showcase_*`) | Veritabanı | Evet — "veriyi sil" ile kaldırılır |
| `product` başlangıç türü + kategori sözlüğü | Veritabanı | Evet |
| `showcase.*` yetkileri | `CapabilityRegistry` (derleme zamanı) | Otomatik |
| `member` / `editor` rollerine yetki satırları | `cp-content/config/sync/user.role.*.yaml` | Evet — kaldırmada silinir |

Rol dosyalarına dokunulmasının sebebi şu: yetkiler verilmezse modül etkinleştikten
sonra üyeler boş bir gönderim sayfası görür ve nedenini anlamak için YAML
düzenlemek zorunda kalır. Eklenen satırlar `# Added by the Showcase module
installer.` yorumuyla işaretlenir; dosya **yeniden yazılmaz**, satır eklenir —
böylece mevcut yorumlarınız korunur. `*` (joker) taşıyan bir rol dosyasına hiç
dokunulmaz. Dosya salt okunursa kurulum sessizce geçer; yetkileri elle ekleyebilirsiniz.

---

## Diğer modüllerle entegrasyon

Modül **hiçbir yerde** `Modules\Forum` veya `Modules\Blog` sınıfı kullanmaz. Bağlar
şu şekilde kurulur:

**Vitrin → dışarı (bağlantılar).** Sahip bir URL yapıştırır; `ShowcaseLinkService`
adresi **router'a sorar**. Site içindeyse bağlantı *rota adı + parametre* olarak
saklanır, dışarıdaysa mutlak adres olarak. Sonuç:

- Forum kapatılırsa rotası kaybolur, bağlantı sessizce gizlenir — ziyaretçi ölü
  bağlantı görmez.
- Bir modül URL şemasını değiştirirse kendi gelen bağlantılarını da taşımış olur.
- `/admin`, `/aacp`, `/api`, `/hesap` altındaki adresler iç bağlantı olarak kabul
  edilmez.

**Dışarı → vitrin.** Üç yol:

1. Twig yardımcıları — tema veya başka bir modül şablonu tek satırla kart basar:
   ```twig
   {{ cp_showcase_card(cp_showcase_item(12)) }}
   {% for item in cp_showcase_items(6, {featured: true}) %} … {% endfor %}
   ```
2. `#[CpResource(name: 'showcase_item')]` — herhangi bir içerik paketine
   `reference` tipi alan eklenip hedefi `resource:showcase_item` yapılabilir.
   Blog yazısı böylece bir ürünü işaret eder.
3. `blog.render.sidebar` kanca noktası — Blog kurulu ise blog kenar çubuğunda öne
   çıkan ürünler görünür.

**Olay kancaları.** `showcase.item.created` ve `showcase.item.published` tetiklenir.
Bir site, ürün yayınlanınca forumda destek konusu açmak gibi kendi entegrasyonunu
düz dosya kancasıyla ekleyebilir — modülü değiştirmeden.

**Ayrıca:** site geneli arama (`ShowcaseSearchProvider`), `/api/showcase/items` ve
`/api/showcase/types` uç noktaları, bildirim türleri ve Studio ana sayfa modu.

---

## Güvenlik

- **Yetkilendirme** tek kapıdan: `is_granted()` → `CPaliusVoter`. `.own` kararını
  voter `OwnableInterface` üzerinden verir; hiçbir denetleyicide elle sahiplik
  karşılaştırması yoktur. `ROLE_*` kontrolü yoktur (Yasa 4).
- **Mass assignment** imkânsız: özel alan değerleri yalnızca `FieldValuePersister`
  üzerinden yazılır ve sadece bir `FieldDefinition` ile desteklenen anahtarlar
  kabul edilir (Yasa 5.3).
- **XSS**: gövde `TextFormatProcessor::sanitizeForStorage()` ile temizlenir; metin
  biçimi her yazmada `TextFormatAccess` ile yeniden çözülür, yani üye gizli input
  değiştirerek `full_html` yazamaz. Değerlendirme yorumları düz metindir.
- **URL'ler** `ShowcaseUrlValidator`'dan geçer: yalnızca http/https; kontrol
  karakterleri temizlenir, böylece `java\nscript:` yeniden birleşemez. Yönlendirme
  anında adres **tekrar** doğrulanır.
- **Yüklemeler** çekirdek `AssetManager`'a devredilir (finfo ile gerçek MIME,
  içerik hash'i, çalıştırılamaz dizin).
- **Kategoriler** yeniden çözülür: gönderilen terim kimliği o türün sözlüğüne ve
  kaydın diline ait değilse düşürülür.
- **CSRF** her POST'ta; **flood koruması** gönderimlerde (`FloodService`).
- **Çok dillilik bütünlüğü** bileşik anahtarlarla: `UNIQUE(slug, locale)` ve
  `UNIQUE(translation_group_id, locale)` (Yasa 5.2).

---

## Tema ile ilişki

Modül kendi ön yüz şablonlarını ve stilini taşır, yani tema düzenlemeden çalışır.
Tema `showcase/<ad>.html.twig` sağlarsa o kazanır (`ShowcaseTemplateResolver`):

```
cp-content/themes/<tema>/Resources/views/showcase/index.html.twig
cp-content/themes/<tema>/Resources/views/showcase/show.html.twig
cp-content/themes/<tema>/Resources/views/showcase/partials/card.html.twig
```

Stil `<style>` bloğu olarak gömülüdür — AssetMapper derlemesi gerektirmez. Tüm
seçiciler `.showcase-*` ön ekiyle korunur; `--showcase-accent` değişkenini
tanımlayarak renkleri temanıza uydurabilirsiniz.

---

## Sayfalar

| URL | Kim |
|---|---|
| `/{dil}/showcase` | Herkes — tüm türler, filtreler |
| `/{dil}/showcase/t/{tür}` | Herkes — tek tür, o türün üretilmiş filtreleri |
| `/{dil}/showcase/{slug}` | Herkes — detay, galeri, özellikler, bağlantılar, değerlendirmeler |
| `/{dil}/showcase/me` | Üye — kendi kayıtları |
| `/admin/showcase/items` | Moderasyon kuyruğu |
| `/admin/showcase/types` | Türler + hazır şablonlar |
| `/admin/showcase/types/{tür}/fields` | O türün alan tasarımcısı |
| `/admin/showcase/reviews` | Değerlendirme moderasyonu |

## Yetkiler

```
showcase.type.manage      showcase.field.manage
showcase.item.create      showcase.item.publish     showcase.item.moderate
showcase.item.view.own    showcase.item.view.any
showcase.item.edit.own    showcase.item.edit.any
showcase.item.delete.own  showcase.item.delete.any
showcase.review.create    showcase.review.moderate
```
