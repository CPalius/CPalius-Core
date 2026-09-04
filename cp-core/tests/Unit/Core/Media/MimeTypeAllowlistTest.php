<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Media;

use App\Core\Media\MimeTypeAllowlist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SEC-01 / SEC-02 regresyon testi — çekirdek izin listesinin kendisi.
 *
 * Bu sınıf, AssetManagerTest'in dayandığı güvenlik POLİTİKASINI ayrı
 * ayrı doğrular: hangi MIME tipinin kabul edildiği ve her birinin hangi
 * KANONİK uzantıya eşlendiği. İkisini ayırmanın sebebi, bir gün birinin
 * "sadece bir tür daha ekleyeyim" diyerek haritaya image/svg+xml
 * yazmasının, dosya yükleme testlerini hiç kırmadan sistemi yeniden
 * savunmasız bırakabilmesidir.
 */
#[CoversClass(MimeTypeAllowlist::class)]
final class MimeTypeAllowlistTest extends TestCase
{
    private MimeTypeAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->allowlist = new MimeTypeAllowlist();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedTypeProvider(): iterable
    {
        yield 'JPEG'            => ['image/jpeg', 'jpg'];
        yield 'JPEG (pjpeg)'    => ['image/pjpeg', 'jpg'];
        yield 'PNG'             => ['image/png', 'png'];
        yield 'GIF'             => ['image/gif', 'gif'];
        yield 'WebP'            => ['image/webp', 'webp'];
        yield 'AVIF'            => ['image/avif', 'avif'];
        yield 'BMP'             => ['image/bmp', 'bmp'];
        yield 'BMP (x-ms)'      => ['image/x-ms-bmp', 'bmp'];
        yield 'TIFF'            => ['image/tiff', 'tiff'];
        yield 'ICO'             => ['image/x-icon', 'ico'];
        yield 'PDF'             => ['application/pdf', 'pdf'];
        yield 'MP4'             => ['video/mp4', 'mp4'];
        yield 'WebM'            => ['video/webm', 'webm'];
        yield 'QuickTime'       => ['video/quicktime', 'mov'];
    }

    #[DataProvider('allowedTypeProvider')]
    public function testAllowedTypeMapsToCanonicalExtension(string $mimeType, string $expectedExtension): void
    {
        self::assertTrue($this->allowlist->isAllowed($mimeType));
        self::assertSame($expectedExtension, $this->allowlist->extensionFor($mimeType));
    }

    /**
     * Reddedilmesi gereken tipler. Her satır ayrı bir saldırı ya da
     * belirsizlik sınıfını temsil eder; hiçbiri "sadece listede yok"
     * diye değil, SOMUT bir gerekçeyle dışarıdadır.
     *
     * @return iterable<string, array{string}>
     */
    public static function rejectedTypeProvider(): iterable
    {
        // XML tabanlı, tarayıcıda script çalıştırabilen belgeler.
        yield 'SVG (script tasiyabilir)'  => ['image/svg+xml'];
        yield 'HTML'                      => ['text/html'];
        yield 'XHTML'                     => ['application/xhtml+xml'];
        yield 'XML'                       => ['text/xml'];

        // Doğrudan çalıştırılabilir kaynak kod.
        yield 'PHP kaynak'                => ['text/x-php'];
        yield 'PHP (httpd)'               => ['application/x-httpd-php'];
        yield 'Shell script'              => ['text/x-shellscript'];
        yield 'JavaScript'                => ['application/javascript'];

        // İçeriği taranmadan güvenli sayılamayacak kapsayıcılar.
        yield 'ZIP'                       => ['application/zip'];
        yield 'RAR'                       => ['application/x-rar-compressed'];
        yield 'Windows calistirilabilir'  => ['application/x-dosexec'];

        // finfo'nun "bilmiyorum" cevabı: fail-closed olmak ZORUNDA.
        yield 'octet-stream (bilinmeyen)' => ['application/octet-stream'];

        // Kapsam dışı bırakılan aile (bilinçli karar, bkz. MimeTypeAllowlist).
        yield 'Ses (kapsam disi)'         => ['audio/mpeg'];

        yield 'Bos dize'                  => [''];
    }

    #[DataProvider('rejectedTypeProvider')]
    public function testRejectedTypeHasNoExtension(string $mimeType): void
    {
        self::assertFalse(
            $this->allowlist->isAllowed($mimeType),
            sprintf('"%s" izin listesinde OLMAMALIYDI.', $mimeType),
        );
        self::assertNull(
            $this->allowlist->extensionFor($mimeType),
            sprintf('"%s" icin uzanti uretilmemeliydi — fail-closed ihlali.', $mimeType),
        );
    }

    /**
     * finfo bazı sistemlerde MIME tipine parametre ekler. Normalizasyon
     * çalışmazsa "image/jpeg; charset=binary" reddedilir ve meşru
     * yüklemeler sessizce kırılır.
     */
    public function testMimeTypeParametersAreStripped(): void
    {
        self::assertSame('jpg', $this->allowlist->extensionFor('image/jpeg; charset=binary'));
        self::assertSame('png', $this->allowlist->extensionFor('image/png;charset=binary'));
        self::assertSame('pdf', $this->allowlist->extensionFor('  application/pdf  '));
    }

    public function testMimeTypeMatchingIsCaseInsensitive(): void
    {
        self::assertSame('png', $this->allowlist->extensionFor('IMAGE/PNG'));
        self::assertSame('jpg', $this->allowlist->extensionFor('Image/Jpeg'));
    }

    /**
     * Normalizasyon, parametreleri soyarken izin listesini GENİŞLETMEMELİ:
     * "image/svg+xml; charset=utf-8" hâlâ reddedilmelidir.
     */
    public function testNormalisationDoesNotWidenTheAllowlist(): void
    {
        self::assertNull($this->allowlist->extensionFor('image/svg+xml; charset=utf-8'));
        self::assertNull($this->allowlist->extensionFor('TEXT/HTML'));
    }

    public function testAllowedExtensionsAreUniqueAndSorted(): void
    {
        $extensions = $this->allowlist->allowedExtensions();

        self::assertNotEmpty($extensions);
        self::assertSame(array_values(array_unique($extensions)), $extensions, 'Uzantilar benzersiz olmali.');

        $sorted = $extensions;
        sort($sorted);
        self::assertSame($sorted, $extensions, 'Uzantilar alfabetik sirali olmali.');

        // Tehlikeli uzantılar listede ASLA görünmemeli — bu, arayüzde
        // gösterilen "kabul edilen türler" ipucunun da doğru olmasını
        // garanti eder.
        foreach (['php', 'phtml', 'svg', 'html', 'exe', 'sh'] as $dangerous) {
            self::assertNotContains($dangerous, $extensions);
        }
    }

    public function testAllowedMimeTypesListIsConsistentWithLookups(): void
    {
        $mimeTypes = $this->allowlist->allowedMimeTypes();

        self::assertNotEmpty($mimeTypes);

        foreach ($mimeTypes as $mimeType) {
            self::assertTrue($this->allowlist->isAllowed($mimeType));
            self::assertNotNull($this->allowlist->extensionFor($mimeType));
        }
    }
}
