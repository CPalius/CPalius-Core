<?php

declare(strict_types=1);

namespace App\Core\Media;

use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Yüklenen dosyaları cpalius_storage (Flysystem) diskine hash tabanlı bir
 * isimlendirmeyle kaydeder ve karşılığında bir Asset entity'si kalıcı hale
 * getirir. Asset, Node'dan bağımsız 1. sınıf bir vatandaştır (bkz. Asset
 * entity doc-block'u) — bu servis onun TEK yazma kapısıdır.
 *
 * ══ GÜVENLİK SÖZLEŞMESİ (denetim bulguları SEC-01 / SEC-02) ══════════════
 *
 * Bu metot bir GÜVENLİK SINIRIDIR. Manifesto Law 5: "Developer discipline
 * is not trusted; security is enforced by default." Buradan geçen her
 * dosya, çağıranın kim olduğuna bakılmaksızın doğrulanır — Media modülü,
 * başka bir modül, bir tema, bir CLI komutu ya da gelecekte yazılacak bir
 * içe aktarıcı fark etmez.
 *
 * Daha önce durum böyle DEĞİLDİ ve iki ayrı açık vardı:
 *
 *   SEC-02 (katman hatası). Tek doğrulama (finfo + MIME ön ek listesi)
 *   cp-content/modules/Media/Controller/Admin/MediaAdminController içinde,
 *   yani KULLANICI ALANINDAYDI. Bu metot hiçbir kontrol yapmıyordu; onu
 *   doğrudan çağıran herhangi bir kod tüm doğrulamayı atlıyordu.
 *
 *   SEC-01 (istemci kontrollü dosya adı). Uzantı
 *   getClientOriginalExtension() ile İSTEMCİDEN alınıyordu. finfo dosyanın
 *   İÇERİĞİNE bakar, ADINA değil — dolayısıyla içeriği geçerli bir GIF
 *   olan ama "evil.php" adıyla gönderilen bir poliglot dosya doğrulamadan
 *   geçip diske "<hash>.php" olarak yazılıyordu. public/uploads doğrudan
 *   web kökünün altında olduğu için sonuç uzaktan kod çalıştırmaydı.
 *
 * Yeni akış, her iki açığı da kaynağında kapatır:
 *
 *   1. PHP yükleme hatası var mı?            -> InvalidUploadException
 *   2. finfo ile GERÇEK MIME tespiti          -> InvalidUploadException
 *   3. MimeTypeAllowlist kontrolü (fail-closed) -> UnsupportedAssetTypeException
 *   4. İçerik hash'i (sha256) + tekrar kontrolü
 *   5. Uzantı DOĞRULANMIŞ MIME'dan türetilir  <- istemciden ASLA
 *   6. Diske yaz + Asset'i persist et
 *
 * Sıra önemlidir: doğrulama hash'lemeden ÖNCE gelir, böylece reddedilecek
 * bir dosya için gereksiz I/O yapılmaz (büyük dosyalarda ucuz bir DoS
 * yüzeyi olurdu).
 *
 * ══ DERİNLEMESİNE SAVUNMA ════════════════════════════════════════════════
 *
 * Bu metot tek başına yeterli SAYILMAZ. public/uploads/.htaccess (ve Nginx
 * karşılığı, bkz. cp-core/docs/security/uploads-hardening.md) dizini
 * çalıştırılamaz kılar. İki katman birbirinden bağımsızdır: uygulama
 * katmanındaki bir regresyon sunucu katmanı tarafından, sunucu
 * yapılandırmasındaki bir eksiklik uygulama katmanı tarafından yakalanır.
 *
 * NOT (geçmiş veri): bu düzeltmeden ÖNCE yüklenmiş dosyalar diskte hâlâ
 * istemciden gelen uzantıyla durabilir. Onları koruyan şey FAZ 0'da
 * eklenen sunucu yapılandırmasıdır. Geçmiş Asset satırlarının yeniden
 * adlandırılması ayrı bir bakım görevidir ve bu değişikliğin kapsamı
 * dışındadır.
 *
 * ══ TEKRAR ÖNLEME (dedup) ════════════════════════════════════════════════
 *
 * Dosya İÇERİĞİNİN sha256 hash'i hesaplanır. Aynı hash zaten varsa (bkz.
 * AssetRepository::findOneByHash), dosya diske TEKRAR yazılmaz ve yeni bir
 * Asset satırı oluşturulmaz — var olan Asset doğrudan döndürülür. Bu hem
 * depolamada tekrarı önler hem de "aynı görseli iki kez yükledim"
 * durumunda veritabanının şişmesini engeller.
 */
final class AssetManager
{
    public function __construct(
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly MimeTypeAllowlist $mimeTypeAllowlist,
    ) {
    }

    /**
     * @throws InvalidUploadException        Dosya sağlam ulaşmadıysa veya içeriği tespit edilemediyse.
     * @throws UnsupportedAssetTypeException Gerçek MIME tipi çekirdek izin listesinde değilse.
     */
    public function upload(UploadedFile $uploadedFile): Asset
    {
        // ── 1) Dosya bize sağlam ulaştı mı? ──────────────────────────────
        // isValid(), PHP'nin UPLOAD_ERR_* kodlarını kontrol eder: boyut
        // aşımı, kısmi yükleme, eksik geçici dizin. Bu kontrol olmadan
        // kesik bir dosyanın hash'ini alıp "geçerli" bir Asset üretebiliriz.
        if (!$uploadedFile->isValid()) {
            throw new InvalidUploadException(sprintf(
                'Upload failed before validation (PHP error code %d): %s',
                $uploadedFile->getError(),
                $uploadedFile->getErrorMessage(),
            ));
        }

        $pathname = $uploadedFile->getPathname();

        if (!is_file($pathname) || !is_readable($pathname)) {
            throw new InvalidUploadException(sprintf('Uploaded temporary file is not readable: "%s".', $pathname));
        }

        // ── 2) GERÇEK MIME tipini içerikten tespit et ────────────────────
        // finfo BİLİNÇLİ olarak doğrudan kullanılır, UploadedFile::
        // getMimeType() yerine. İkisi de içerik tabanlıdır, ancak
        // Symfony'nin MimeTypes tahmincisi finfo eklentisi yoksa
        // UZANTIYA dayalı bir tahminciye geri düşebilir — tam da
        // kapatmaya çalıştığımız açık. Burada finfo yoksa yükleme
        // reddedilir (fail-closed), sessizce zayıf bir yola düşmez.
        $detectedMimeType = $this->detectMimeType($pathname);

        // ── 3) İzin listesi — fail-closed ────────────────────────────────
        $extension = $this->mimeTypeAllowlist->extensionFor($detectedMimeType);

        if ($extension === null) {
            throw new UnsupportedAssetTypeException($detectedMimeType);
        }

        // ── 4) İçerik hash'i + tekrar kontrolü ───────────────────────────
        $hash = hash_file('sha256', $pathname);

        if ($hash === false) {
            throw new InvalidUploadException(sprintf(
                'Could not compute content hash for uploaded file "%s".',
                $uploadedFile->getClientOriginalName(),
            ));
        }

        $existing = $this->assetRepository->findOneByHash($hash);
        if ($existing !== null) {
            return $existing;
        }

        // ── 5) Dosya adı: uzantı DOĞRULANMIŞ MIME'dan gelir ──────────────
        // İstemcinin gönderdiği ad yalnızca Asset::$originalName alanında,
        // salt görüntüleme amacıyla saklanır ve dosya sistemine ASLA
        // yansımaz. Hash + kanonik uzantı, adın tamamen bizim
        // kontrolümüzde olan iki parçasıdır.
        $filename = $hash.'.'.$extension;
        $path = date('Y/m');
        $storageKey = $path.'/'.$filename;

        // ── 6) Diske yaz ─────────────────────────────────────────────────
        $stream = fopen($pathname, 'r');
        if ($stream === false) {
            throw new InvalidUploadException(sprintf('Could not open uploaded file for reading: "%s".', $pathname));
        }

        try {
            $this->cpaliusStorage->writeStream($storageKey, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $asset = new Asset(
            filename: $filename,
            originalName: $this->sanitizeOriginalName($uploadedFile->getClientOriginalName()),
            path: $path,
            // Depolanan MIME tipi de tespit EDİLEN değerdir; istemcinin
            // gönderdiği Content-Type başlığı hiçbir aşamada güvenilmez.
            mimeType: $detectedMimeType,
            fileSize: $uploadedFile->getSize() ?: (filesize($pathname) ?: 0),
            hash: $hash,
        );

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }

    /**
     * Yüklenen dosyanın MIME tipini İÇERİĞİNDEN tespit eder.
     *
     * finfo eklentisi yoksa veya içerik tespit edilemezse istisna
     * fırlatılır — "bilinmiyorsa geçir" (fail-open) davranışı bu sınıfta
     * kabul edilemez.
     */
    private function detectMimeType(string $pathname): string
    {
        if (!class_exists(\finfo::class)) {
            throw new InvalidUploadException(
                'The "fileinfo" PHP extension is required to validate uploads and is not available.',
            );
        }

        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detected = $finfo->file($pathname);

        if ($detected === false || $detected === '') {
            throw new InvalidUploadException('Could not determine the MIME type of the uploaded file.');
        }

        return $detected;
    }

    /**
     * Orijinal dosya adı yalnızca görüntüleme/indirme amaçlıdır ve dosya
     * sistemine hiç dokunmaz — yine de veritabanına yazılmadan önce
     * temizlenir.
     *
     * Gerekçe: bu değer yönetim arayüzünde (medya listesi, seçici modal)
     * ve silme onay mesajlarında gösterilir. Twig otomatik kaçışı orayı
     * zaten korur, ancak dizin geçişi karakterlerinin (../) ve yeni satır
     * / NUL baytlarının veritabanına hiç girmemesi, bu alanın ileride
     * yanlışlıkla bir dosya yolu üretmek için kullanılması riskini
     * kaynağında ortadan kaldırır (ör. Content-Disposition başlığı).
     *
     * Asset::$originalName kolonu 255 karakterdir; kesme burada yapılır ki
     * uzun bir ad Doctrine seviyesinde bir hataya yol açmasın.
     */
    private function sanitizeOriginalName(string $originalName): string
    {
        // Yalnızca dosya adı bileşenini al (dizin bileşenlerini at).
        $name = basename(str_replace('\\', '/', $originalName));

        // Kontrol karakterlerini ve NUL'u kaldır.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'dosya';
        }

        return mb_substr($name, 0, 255);
    }
}
