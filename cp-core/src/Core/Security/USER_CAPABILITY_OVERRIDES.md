# Kullanıcı yetenek overlay’i

Bu belge, AACP kullanıcı düzenlemesindeki “kullanıcıya özel yetenekler”
katmanının **neden** var olduğunu, **neye dayandığını** ve **neleri
asla değiştirmeyeceğini** kaydeder. Uygulama kodu buradaki sözleşmeye
bağlıdır; bir sonraki değişiklik önce bu belgeden sapıyorsa belgeyi
güncellemek zorundadır.

## Neden

CPalius’ta yetki, Symfony `ROLE_*` değil, kayıtlı **capability
string**’leridir. Tek kapı `CPaliusVoter` + `is_granted()`. Roller
`cp-content/config/sync/user.role.*.yaml` içindedir; sürüm kontrollü
yapılandırma, kullanıcı verisi değil.

AACP kullanıcı formunda yıllarca yalnızca rol atanabildi. Etkin yetkiler
kartı rol birleşiminin okunabilir özetidir, yazılmaz. Bu, çoğu site için
doğrudur: “editör şunu yapar” bir kişilik istisna değil, bir **sınıftır**.

İhtiyaç farklılaşınca (bu üye yorumları yönetsin, şu editör forum
konusu açamasın) iki kötü yol vardır:

1. **Yeni rol türetmek.** Her istisna yeni YAML, yeni senkron, yeni
   “aslında editor ama…” kimliği. Rol grafiği şişer, varsayılana dönüş
   kaybolur.
2. **Rol YAML’ını kullanıcıya göre yamamak.** Config artık kullanıcı
   verisidir; güncelleme ve site kopyası birbirini ezer.

WordPress tarzı kullanıcı-checkbox matrisi üçüncü kötü yoldur: iki yüz
kutunun varsayılanı görünmez, `system.aacp.access` bir tıkla verilir,
“reset” diye bir şey kalmaz.

Overlay, rolü **varsayılan** bırakır; kullanıcıya yalnızca seyrek
`inherit | grant | deny` satırları ekler. Reset satırları siler. Çekirdek
karar sırası aynı kapıdadır.

## Dayanak

- Manifesto Law 4: yetki `is_granted(capability)`, asla `ROLE_*`.
- Manifesto Law 6.1: istek başına tablo başına en fazla 10 SELECT.
  Overlay, kullanıcı başına **tek** `SELECT`, istek içi memo.
- Bilinmeyen yetenek = yok say / reddet. Registry’de olmayan string
  grant üretmez.
- Forum bölüm ACL’i (`ForumPermissionService`) **ayrı** bir katmandır:
  section × group / kullanıcı overlay’i. Bu sistem `forum.topic.moderate`
  gibi **modül capability** string’lerini değiştirir; “şu board’da
  yazabilir” kararını çalmaz.
- T1.4 kayıt grantı ve T1.5 Access olayı durur. Overlay **deny** onları
  da ezer; overlay **grant** yalnızca delegable capability için rol
  birleşimine eklenir, sonra `.own` / T1.4 / T1.5 aynıdır.

## Karar sırası (`CPaliusVoter`)

1. Registry: bilinmeyen attribute abstain.
2. Kullanıcı aktif değilse deny.
3. Overlay **deny** → hemen `false`. Rol, T1.4, T1.5 okunmaz.
4. Rol birleşimi **veya** overlay **grant** (yalnızca `canGrant`).
5. `.own` ise `OwnableInterface` + sahip eşleşmesi.
6. T1.4 per-record grant.
7. T1.5 Access event.

Yazar (`UserCapabilityOverrideWriter`) voter’a yazmaz. Voter, kilitli bir
`grant` satırını (ör. SQL ile sokulmuş `system.aacp.access`) **yok sayar**.
Deny satırı yazıldığı gibi uygulanır: son-yönetici koruması yazma
anındadır. Ham SQL ile son yöneticiye `system.aacp.access` deny yazmak,
son admin satırını silmek kadar operatör hatasıdır.

## Üç durum

| Durum     | Anlam                                      | Saklama        |
|-----------|--------------------------------------------|----------------|
| `inherit` | Rol (ve T1.4 / T1.5) konuşur               | Satır yok      |
| `grant`   | Rolde olmasa da bu capability verilir      | `effect=grant` |
| `deny`    | Rolde olsa da bu capability reddedilir     | `effect=deny`  |

Aynı kullanıcı + capability için en fazla bir satır.

## GRANT kilitleri

Overlay **veremez**:

- `system.*` (AACP, kullanıcı, güncelleme, modül, log, güvenlik, …)
- Kayıtlı `.own` / `.any` kardeşi olan **kapsamsız** isim
  (`forum.post.edit` varken yalnızca `.own` / `.any` verilebilir)

Bunlar rol YAML’ına aittir. Root AACP’den rol atar; overlay ile
“yarı-root” üretilemez.

`admin.access` / `admin.dashboard.view` `system.*` değildir; Studio’ya
istisna grant **bilinçli** olarak açıktır.

## DENY kilitleri

Şu küme, **son admin** üzerinde ve **kendi hesabında** reddedilemez:

- `system.aacp.access`
- `system.users.manage`
- `system.module.manage`
- `system.update.manage`

Aksi halde site, AACP’yi açacak veya rol atayacak kimsesiz kalır.
İkinci bir admin, birinciden AACP’yi deny edebilir.

## Yazma kuralları

- Yalnızca AACP, `system.users.manage`, ayrı POST (kullanıcı formuna
  karışmaz, `UserType` değişmez).
- CSRF: `aacp_user_overrides_{id}` / `aacp_user_overrides_reset_{id}`.
- Geçersiz / kilitli bir alan **tüm** isteği düşürür; kısmi kayıt yok.
- Boş `overrides` dizisi no-op’tur (kazara wipe değil). Reset ayrı
  aksiyondur.
- Reset: kullanıcının overlay satırlarını siler. Rol YAML’a dokunmaz.
- Kullanıcı silinince satırlar `ON DELETE CASCADE` ile gider.
- `AuditLog` `resource_name=user.capability_override`, `resource_id`
  hedef kullanıcı, `changes` alan farkları. Audit yazılamazsa overlay
  kaydı geri alınmaz.

## Performans ve fail-safe

- `UserCapabilityOverrideStore`: kullanıcı başına bir sorgu, memo.
  Writer `forget()` eder.
- Tablo yoksa / sorgu patlarsa store **boş overlay** döner (rol
  varsayılanı). Site düşmez; yetki genişlemez.

## UI sözleşmesi

- Yalnızca düzenleme (kayıtlı kullanıcı). Oluşturmada overlay yok.
- Modül accordion; sapma olanlar açık ve vurgulu.
- GRANT radyosu yalnızca `canGrant`; DENY radyosu yalnızca `canDeny`.
- Ham 200 kutu duvarı yok: varsayılan inherit, fark görünür.

## Bilinçli olarak yapılmayanlar

- Rol YAML’ını DB’ye taşımak.
- Forum section ACL’ini buraya birleştirmek.
- Overlay’den `*` vermek.
- Voter’da son-admin kontrolü (her `is_granted` için tüm kullanıcıları
  taramak Law 6.1’i bozar).
- Twig veya controller’da ikinci bir yetki motoru.

## Şema

Tablo: `cp_user_capability_overrides`

- `user_id` → `cp_users.id` ON DELETE CASCADE
- `capability` VARCHAR(150)
- `effect` `grant` | `deny`
- UNIQUE (`user_id`, `capability`)

Migration: `Version20260923010000`.

## Operatör notu

Canlı sitede tabloyu oluşturmak için normal çekirdek güncelleme
hattı yeterlidir (`cp:update` / Doctrine migrations). Overlay
satırları config sync’e girmez; site kopyasında kullanıcıyla birlikte
taşınır veya taşınmaz — rol dosyaları gibi değil.
