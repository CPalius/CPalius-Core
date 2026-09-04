<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Media;

use App\Core\Media\AssetManager;
use App\Core\Media\Exception\AssetUploadException;
use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Core\Media\MimeTypeAllowlist;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * SEC-01 / SEC-02 REGRESYON TESTİ — dosya yükleme güvenlik sınırı.
 *
 * ══ Bu testin var olma sebebi ═══════════════════════════════════════════
 *
 * Denetim öncesi AssetManager::upload() iki açık taşıyordu:
 *
 *   SEC-01: depolanan dosya adının uzantısı İSTEMCİDEN alınıyordu
 *           ($uploadedFile->getClientOriginalExtension()). İçeriği geçerli
 *           bir GIF olan ama "evil.php" adıyla gönderilen bir poliglot
 *           dosya, public/uploads altına "<hash>.php" olarak yazılıyor ve
 *           doğrudan çağrılabiliyordu -> uzaktan kod çalıştırma.
 *
 *   SEC-02: MIME doğrulaması çekirdekte DEĞİL, Media modülünün
 *           controller'ındaydı. AssetManager'ı doğrudan çağıran herhangi
 *           bir kod (başka bir modül, bir tema, bir CLI komutu) tüm
 *           kontrolü atlıyordu.
 *
 * ══ Bu test neden mock'lu bir birim testi ═══════════════════════════════
 *
 * Disk ve veritabanı BİLİNÇLİ olarak taklit edilir, ama dosya İÇERİĞİ
 * ve finfo tespiti GERÇEKTİR. Test edilen şey depolama katmanı değil,
 * KARARdır: "hangi baytlar hangi dosya adına dönüşür ve hangileri
 * reddedilir?" Flysystem'e giden storage key'i yakalayarak bu kararı
 * doğrudan gözlemleriz — gerçek bir dosya sistemine yazmadan.
 */
#[CoversClass(AssetManager::class)]
final class AssetManagerTest extends TestCase
{
    /**
     * 1x1 saydam GIF'in gerçek baytları. Testlerde "geçerli görsel"
     * olarak kullanılır; finfo bunu image/gif olarak tanır.
     */
    private const REAL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    /** 8 baytlık gerçek PNG imzası + minimal IHDR. */
    private const REAL_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    /**
     * Testlerde kullanılan PHP gövdesi ZARARSIZDIR ve bu BİLİNÇLİ bir
     * karardır.
     *
     * Testin iddiası "içeriği PHP olarak tespit edilen dosya reddedilir"
     * olduğu için, gövdenin gerçekten silahlandırılmış olması gerekmez —
     * finfo her iki durumda da "text/x-php" döndürür.
     *
     * Buna karşılık gerçek bir webshell imzası (ör. system($_GET[...]))
     * kullanmak testi TAŞINAMAZ hâle getirir: Windows Defender gibi
     * gerçek zamanlı korumalar böyle bir dosyayı diske yazıldığı anda
     * kilitler. Dosya var görünür, filesize() doğru değeri döner,
     * is_readable() bile true der — ama fopen() ve finfo::file()
     * "Invalid argument" ile başarısız olur. Sonuç, gerçek bir regresyonu
     * değil yalnızca geliştiricinin antivirüsünü raporlayan bir testtir.
     *
     * (Bu davranış FAZ 2 sırasında bu makinede birebir gözlemlendi ve
     * doğrulandı; bkz. teslim notları.)
     */
    private const BENIGN_PHP = '<?php echo 1; ?>';

    private string $workDir;

    private FilesystemOperator&MockObject $storage;

    private EntityManagerInterface&MockObject $entityManager;

    private AssetRepository&MockObject $assetRepository;

    private AssetManager $assetManager;

    /** @var list<string> Flysystem'e yazılan storage key'ler. */
    private array $writtenKeys = [];

    protected function setUp(): void
    {
        // Çalışma dizini proje ağacındadır: Windows'ta finfo, ASCII
        // olmayan yollarda (ör. "C:\Users\Ali Çömez\...") dosya açamaz.
        $this->workDir = \dirname(__DIR__, 4).'/var/test-uploads';

        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0775, true);
        }

        $this->writtenKeys = [];

        $this->storage = $this->createMock(FilesystemOperator::class);
        $this->storage->method('writeStream')
            ->willReturnCallback(function (string $location): void {
                $this->writtenKeys[] = $location;
            });

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);

        // İzin listesi GERÇEKTİR: test edilen güvenlik politikasının
        // kendisi taklit edilirse test hiçbir şey kanıtlamaz.
        $this->assetManager = new AssetManager(
            $this->storage,
            $this->entityManager,
            $this->assetRepository,
            new MimeTypeAllowlist(),
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // SEC-01 — Uzantı içerikten türetilir, istemciden ASLA
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Denetim raporundaki tam saldırı zinciri: geçerli GIF başlığı
     * taşıyan bir poliglot, ".php" adıyla gönderiliyor.
     */
    public function testPolyglotGifNamedAsPhpIsStoredWithGifExtension(): void
    {
        $upload = $this->createUpload(
            'evil.php',
            self::REAL_GIF.self::BENIGN_PHP,
        );

        $this->assetRepository->method('findOneByHash')->willReturn(null);

        $asset = $this->assetManager->upload($upload);

        self::assertCount(1, $this->writtenKeys, 'Diske tam olarak bir dosya yazilmaliydi.');

        $storageKey = $this->writtenKeys[0];

        self::assertStringEndsWith('.gif', $storageKey, 'Uzanti ICERIKTEN turetilmeliydi.');
        self::assertStringNotContainsString('.php', $storageKey, 'Istemci uzantisi diske SIZDI — SEC-01 regresyonu.');
        self::assertStringEndsWith('.gif', $asset->getFilename());
        self::assertSame('image/gif', $asset->getMimeType(), 'Depolanan MIME de tespit EDILEN deger olmali.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deceptiveFilenameProvider(): iterable
    {
        yield 'php uzantisi'            => ['evil.php', 'gif'];
        yield 'phtml uzantisi'          => ['evil.phtml', 'gif'];
        yield 'phar uzantisi'           => ['evil.phar', 'gif'];
        yield 'cift uzanti'             => ['evil.gif.php', 'gif'];
        yield 'buyuk harf PHP'          => ['EVIL.PHP', 'gif'];
        yield 'bosluk sonlu'            => ['evil.php ', 'gif'];
        yield 'yanlis ama zararsiz'     => ['photo.jpg', 'gif'];
        yield 'uzantisiz'               => ['photo', 'gif'];
    }

    /**
     * İstemci adı ne olursa olsun, diskteki uzantı içerikten gelir.
     */
    #[DataProvider('deceptiveFilenameProvider')]
    public function testClientFilenameNeverDeterminesStoredExtension(string $clientName, string $expectedExtension): void
    {
        $upload = $this->createUpload($clientName, self::REAL_GIF);
        $this->assetRepository->method('findOneByHash')->willReturn(null);

        $this->assetManager->upload($upload);

        self::assertStringEndsWith(
            '.'.$expectedExtension,
            $this->writtenKeys[0],
            sprintf('"%s" adiyla gonderilen dosya .%s olarak saklanmaliydi.', $clientName, $expectedExtension),
        );
    }

    /**
     * Depolanan ad tamamen bizim kontrolümüzde olmalı: sha256 hash
     * (64 onaltılık karakter) + kanonik uzantı, "YYYY/MM/" dizini altında.
     */
    public function testStoredFilenameIsHashPlusCanonicalExtension(): void
    {
        $bytes = self::REAL_PNG;
        $upload = $this->createUpload('../../../etc/passwd.php', $bytes);
        $this->assetRepository->method('findOneByHash')->willReturn(null);

        $asset = $this->assetManager->upload($upload);

        self::assertMatchesRegularExpression(
            '#^\d{4}/\d{2}/[0-9a-f]{64}\.png$#',
            $this->writtenKeys[0],
            'Storage key beklenen "YYYY/MM/<sha256>.png" bicimlerinde olmali.',
        );
        self::assertSame(hash('sha256', $bytes), $asset->getHash());

        // Dizin geçişi denemesi orijinal ada da yansımamalı.
        self::assertSame('passwd.php', $asset->getOriginalName());
        self::assertStringNotContainsString('..', $asset->getOriginalName());
        self::assertStringNotContainsString('/', $asset->getOriginalName());
    }

    // ═════════════════════════════════════════════════════════════════════
    // SEC-02 — İzin listesi çekirdekte, fail-closed
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rejectedUploadProvider(): iterable
    {
        yield 'saf PHP shell' => [
            'shell.php',
            '<?php echo 1; ?>',
            'text/x-php',
        ];
        yield 'script tasiyan SVG' => [
            'evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'image/svg+xml',
        ];
        yield 'HTML belge' => [
            'page.html',
            '<!doctype html><html><body><script>alert(1)</script></body></html>',
            'text/html',
        ];
    }

    /**
     * Reddedilen türler UnsupportedAssetTypeException fırlatmalı ve
     * diske HİÇBİR ŞEY yazılmamalıdır.
     */
    #[DataProvider('rejectedUploadProvider')]
    public function testDisallowedContentIsRejectedBeforeAnyWrite(
        string $clientName,
        string $contents,
        string $expectedDetectedMime,
    ): void {
        $upload = $this->createUpload($clientName, $contents);
        $this->assetRepository->expects(self::never())->method('findOneByHash');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');
        $this->storage->expects(self::never())->method('writeStream');

        try {
            $this->assetManager->upload($upload);
            self::fail(sprintf('"%s" reddedilmeliydi ama kabul edildi.', $clientName));
        } catch (UnsupportedAssetTypeException $e) {
            self::assertStringStartsWith(
                $expectedDetectedMime,
                $e->detectedMimeType,
                'Istisna, finfo tarafindan tespit edilen gercek MIME tipini tasimali.',
            );
        }

        self::assertSame([], $this->writtenKeys, 'Reddedilen dosya diske YAZILMAMALIYDI.');
    }

    /**
     * SVG, denetimden önce "image/" ön eki tarafından kabul ediliyordu.
     * Bu test o özel regresyonu izler.
     */
    public function testSvgIsRejectedEvenThoughItIsAnImageType(): void
    {
        $upload = $this->createUpload(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="9"/></svg>',
        );

        $this->expectException(UnsupportedAssetTypeException::class);

        $this->assetManager->upload($upload);
    }

    /**
     * Her reddin ortak atası AssetUploadException olmalı: çağıran taraf
     * (MediaAdminController) tek bir catch ile 400 döndürebilsin.
     */
    public function testAllRejectionsShareTheCommonBaseException(): void
    {
        $upload = $this->createUpload('shell.php', '<?php echo 1; ?>');

        $this->expectException(AssetUploadException::class);

        $this->assetManager->upload($upload);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Geçerli yükleme ve tekrar önleme
    // ═════════════════════════════════════════════════════════════════════

    public function testValidPngIsAcceptedAndPersisted(): void
    {
        $upload = $this->createUpload('logo.png', self::REAL_PNG);
        $this->assetRepository->method('findOneByHash')->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $asset = $this->assetManager->upload($upload);

        self::assertSame('image/png', $asset->getMimeType());
        self::assertSame('logo.png', $asset->getOriginalName());
        self::assertStringEndsWith('.png', $asset->getFilename());
        self::assertSame(\strlen(self::REAL_PNG), $asset->getFileSize());
    }

    /**
     * Aynı içerik ikinci kez yüklendiğinde diske tekrar yazılmaz ve yeni
     * bir satır oluşturulmaz — var olan Asset döner.
     */
    public function testDuplicateContentReturnsExistingAssetWithoutWriting(): void
    {
        $existing = new Asset(
            filename: 'abc.png',
            originalName: 'onceki.png',
            path: '2026/01',
            mimeType: 'image/png',
            fileSize: 123,
            hash: hash('sha256', self::REAL_PNG),
        );

        $this->assetRepository
            ->expects(self::once())
            ->method('findOneByHash')
            ->with(hash('sha256', self::REAL_PNG))
            ->willReturn($existing);

        $this->entityManager->expects(self::never())->method('persist');
        $this->storage->expects(self::never())->method('writeStream');

        $result = $this->assetManager->upload($this->createUpload('yeni.png', self::REAL_PNG));

        self::assertSame($existing, $result);
        self::assertSame([], $this->writtenKeys);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Bozuk yüklemeler
    // ═════════════════════════════════════════════════════════════════════

    /**
     * PHP yükleme hatası (ör. boyut aşımı) doğrulamadan ÖNCE yakalanmalı;
     * kesik bir dosyanın hash'i alınıp "geçerli" bir Asset üretilmemeli.
     */
    public function testUploadWithPhpErrorIsRejected(): void
    {
        $path = $this->writeTempFile('kirik.png', self::REAL_PNG);
        $upload = new UploadedFile($path, 'kirik.png', null, \UPLOAD_ERR_INI_SIZE, true);

        $this->storage->expects(self::never())->method('writeStream');

        $this->expectException(InvalidUploadException::class);

        $this->assetManager->upload($upload);
    }

    /**
     * Boş dosya: finfo bunu "application/x-empty" veya "inode/x-empty"
     * olarak tanır; her iki durumda da izin listesinde yoktur ve
     * reddedilmelidir (fail-closed).
     */
    public function testEmptyFileIsRejected(): void
    {
        $upload = $this->createUpload('bos.png', '');

        $this->storage->expects(self::never())->method('writeStream');

        $this->expectException(AssetUploadException::class);

        $this->assetManager->upload($upload);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Yardımcılar
    // ═════════════════════════════════════════════════════════════════════

    private function createUpload(string $clientName, string $contents): UploadedFile
    {
        $path = $this->writeTempFile($clientName, $contents);

        // $test: true -> isValid() gerçek bir HTTP yüklemesi olmadan da
        // UPLOAD_ERR_OK'a bakarak çalışır (is_uploaded_file() atlanır).
        // MIME argümanı BİLİNÇLİ olarak null: AssetManager onu zaten
        // kullanmaz, kendi finfo tespitini yapar — ve testin bunu
        // varsayması değil, doğrulaması gerekir.
        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function writeTempFile(string $clientName, string $contents): string
    {
        $path = $this->workDir.'/'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($path, $contents);

        return $path;
    }
}
