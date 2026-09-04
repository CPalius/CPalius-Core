<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * Yüklenebilir dosya türlerinin ÇEKİRDEKTEKİ tek doğruluk kaynağı ve aynı
 * zamanda "hangi MIME tipi hangi uzantıyla saklanır" haritası.
 *
 * ── Neden bu sınıf var (denetim bulgusu SEC-01/SEC-02) ────────────────────
 *
 * Bu kontrol daha önce cp-content/modules/Media/Controller/Admin/
 * MediaAdminController içinde, ön ek tabanlı bir listeyle yapılıyordu:
 *
 *     ALLOWED_MIME_PREFIXES = ['image/', 'application/pdf', 'video/']
 *
 * İki ayrı sorun vardı:
 *
 *   1) KATMAN HATASI. Kontrol kullanıcı alanındaydı (cp-content), tek
 *      yazma kapısı olan AssetManager::upload() ise çekirdekteydi ve
 *      HİÇBİR doğrulama yapmıyordu. Media modülü devre dışı bırakılsa ya
 *      da başka bir modül/tema AssetManager'ı doğrudan çağırsa, tüm
 *      kontrol atlanıyordu. Manifesto Law 5: "Developer discipline is not
 *      trusted; security is enforced by default." — güvenlik sınırı
 *      çekirdekte olmak zorundadır.
 *
 *   2) ÖN EK EŞLEŞTİRME ÇOK GENİŞTİ. "image/" öneki image/svg+xml'i de
 *      kabul ediyordu. SVG bir XML belgesidir, <script> ve olay
 *      öznitelikleri taşıyabilir ve public/uploads altından doğrudan
 *      sunulduğunda tarayıcıda ÇALIŞIR — yani kalıcı XSS.
 *
 * ── Neden bir HARİTA, sadece bir liste değil ──────────────────────────────
 *
 * Asıl açık, izin listesinin kendisi değil, DOSYA ADININ nereden geldiğiydi.
 * AssetManager uzantıyı getClientOriginalExtension() ile İSTEMCİDEN
 * alıyordu. finfo içeriğe bakar, ADA bakmaz; dolayısıyla içeriği geçerli
 * bir GIF olan ama "evil.php" adıyla gönderilen bir poliglot dosya
 * doğrulamayı geçip diske "<hash>.php" olarak yazılıyordu.
 *
 * Bu yüzden burası bir liste değil, MIME -> uzantı HARİTASIdır: uzantı
 * artık istemciden değil, DOĞRULANMIŞ MIME tipinden türetilir. İstemcinin
 * gönderdiği ad yalnızca Asset::$originalName alanında, salt görüntüleme
 * amacıyla saklanır ve hiçbir zaman dosya sistemine yansımaz.
 *
 * ── Neden bu liste modüller tarafından genişletilemez ─────────────────────
 *
 * Bilinçli bir karardır. Yeni bir dosya türüne izin vermek bir GÜVENLİK
 * kararıdır, bir yapılandırma tercihi değil; her yeni tür "bu içerik
 * tarayıcıda aktif olarak yorumlanabilir mi?" sorusunun tek tek
 * cevaplanmasını gerektirir. Bir modülün kendi listesini enjekte
 * edebilmesi, SEC-02'de kapattığımız katman hatasını farklı bir kapıdan
 * geri getirirdi. Yeni tür ihtiyacı çekirdek değişikliği (ve kod
 * incelemesi) ile karşılanır.
 *
 * @see AssetManager::upload() Bu listeyi uygulayan tek yer.
 * @see cp-core/docs/security/uploads-hardening.md Sunucu tarafı ikinci savunma hattı.
 */
final class MimeTypeAllowlist
{
    /**
     * Anahtar: finfo(FILEINFO_MIME_TYPE) tarafından döndürülen MIME tipi.
     * Değer: diskte kullanılacak KANONİK uzantı (noktasız, küçük harf).
     *
     * Aynı biçim için birden fazla MIME tipi olabilir (ör. bazı sistemler
     * JPEG için "image/pjpeg" döner); hepsi aynı kanonik uzantıya eşlenir,
     * böylece diskte tek bir uzantı ailesi oluşur.
     *
     * BİLİNÇLİ OLARAK LİSTE DIŞI BIRAKILANLAR:
     *
     *   image/svg+xml  -> XML'dir; <script>, onload=, <foreignObject>
     *                     taşıyabilir ve tarayıcıda çalıştırılır.
     *   text/html      -> aynı gerekçe, doğrudan çalıştırılabilir belge.
     *   text/xml, application/xml
     *                  -> XXE ve XSLT yüzeyi; medya kütüphanesinde işi yok.
     *   application/zip, application/x-rar, application/gzip
     *                  -> arşivler; içeriği taranmadan güvenli sayılamaz.
     *   application/octet-stream
     *                  -> finfo'nun "bilmiyorum" cevabıdır. Bunu kabul
     *                     etmek, tanınmayan HER şeyi kabul etmek demektir
     *                     — fail-open olurdu, fail-closed olmalı.
     *
     * @var array<string, string>
     */
    private const MAP = [
        // ── Görseller ────────────────────────────────────────────────────
        'image/jpeg'                => 'jpg',
        'image/pjpeg'               => 'jpg',
        'image/png'                 => 'png',
        'image/gif'                 => 'gif',
        'image/webp'                => 'webp',
        'image/avif'                => 'avif',
        'image/bmp'                 => 'bmp',
        'image/x-ms-bmp'            => 'bmp',
        'image/tiff'                => 'tiff',
        'image/heic'                => 'heic',
        'image/heif'                => 'heif',
        'image/vnd.microsoft.icon'  => 'ico',
        'image/x-icon'              => 'ico',

        // ── Belgeler ─────────────────────────────────────────────────────
        // Yalnızca PDF. PDF de JavaScript taşıyabilir, ancak modern
        // tarayıcıların yerleşik görüntüleyicileri bunu sandbox içinde
        // çalıştırır ve uploads/.htaccess'teki CSP "sandbox" direktifi
        // ikinci bir bariyer kurar (bkz. FAZ 0).
        'application/pdf'           => 'pdf',

        // ── Video ────────────────────────────────────────────────────────
        'video/mp4'                 => 'mp4',
        'video/webm'                => 'webm',
        'video/ogg'                 => 'ogv',
        'video/quicktime'           => 'mov',
        'video/x-matroska'          => 'mkv',

        // ── Ses ──────────────────────────────────────────────────────────
        // Önceki ön ek listesinde ("image/", "application/pdf", "video/")
        // ses YOKTU. Kapsam korunur: burada da yoktur. Ses desteği
        // gerektiğinde bu haritaya bilinçli olarak eklenmelidir.
    ];

    public function isAllowed(string $mimeType): bool
    {
        return isset(self::MAP[$this->normalize($mimeType)]);
    }

    /**
     * Verilen MIME tipi için diskte kullanılacak kanonik uzantıyı döner.
     * İzin listesinde yoksa null — çağıran taraf bunu bir RET olarak
     * yorumlamalıdır, "varsayılan bir uzantı uydur" olarak DEĞİL.
     */
    public function extensionFor(string $mimeType): ?string
    {
        return self::MAP[$this->normalize($mimeType)] ?? null;
    }

    /**
     * İzin verilen tüm MIME tipleri — yükleme formunun "accept"
     * özniteliğini üretmek ve hata mesajlarında kullanıcıya neyin kabul
     * edildiğini göstermek için.
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * İzin verilen benzersiz uzantılar (alfabetik) — arayüzde
     * "jpg, png, webp, pdf, mp4 …" gibi bir ipucu göstermek için.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $extensions = array_values(array_unique(array_values(self::MAP)));
        sort($extensions);

        return $extensions;
    }

    /**
     * finfo bazı sistemlerde MIME tipine parametre ekler
     * (ör. "image/jpeg; charset=binary") ve büyük/küçük harf tutarsız
     * olabilir. Karşılaştırmadan önce sadece "tip/alt-tip" kısmını,
     * küçük harfe indirgenmiş biçimde alırız.
     */
    private function normalize(string $mimeType): string
    {
        $mimeType = trim($mimeType);

        $separatorPosition = strpos($mimeType, ';');
        if ($separatorPosition !== false) {
            $mimeType = substr($mimeType, 0, $separatorPosition);
        }

        return strtolower(trim($mimeType));
    }
}
