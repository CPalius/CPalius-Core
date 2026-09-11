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
 * SEC-01 / SEC-02 regression test — upload security boundary.
 * Storage is mocked; file content and finfo detection are real.
 */
#[CoversClass(AssetManager::class)]
final class AssetManagerTest extends TestCase
{
    /** Real bytes of a 1x1 transparent GIF — finfo detects image/gif. */
    private const REAL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    /** Real 8-byte PNG signature plus minimal IHDR. */
    private const REAL_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    /**
     * Benign PHP body — finfo returns text/x-php without triggering AV locks.
     * Real webshell signatures break finfo on Windows Defender (see delivery notes).
     */
    private const BENIGN_PHP = '<?php echo 1; ?>';

    private string $workDir;

    private FilesystemOperator&MockObject $storage;

    private EntityManagerInterface&MockObject $entityManager;

    private AssetRepository&MockObject $assetRepository;

    private AssetManager $assetManager;

    /** @var list<string> Storage keys written to Flysystem. */
    private array $writtenKeys = [];

    protected function setUp(): void
    {
        // Work dir under project tree — finfo fails on non-ASCII Windows paths.
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

        // Real allowlist — mocking the policy would prove nothing.
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

    // SEC-01 — Extension from content, never from client

    /** Audit-report attack chain: GIF polyglot sent as .php filename. */
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

    /** Stored extension always comes from content, regardless of client name. */
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

    /** Stored name is sha256 hash + canonical extension under YYYY/MM/. */
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

        // Path traversal must not leak into original name.
        self::assertSame('passwd.php', $asset->getOriginalName());
        self::assertStringNotContainsString('..', $asset->getOriginalName());
        self::assertStringNotContainsString('/', $asset->getOriginalName());
    }

    // SEC-02 — Allowlist in core, fail-closed

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

    /** Rejected types throw UnsupportedAssetTypeException and write nothing to disk. */
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

    /** SVG was previously accepted via image/ prefix — tracks that regression. */
    public function testSvgIsRejectedEvenThoughItIsAnImageType(): void
    {
        $upload = $this->createUpload(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="9"/></svg>',
        );

        $this->expectException(UnsupportedAssetTypeException::class);

        $this->assetManager->upload($upload);
    }

    /** All rejections extend AssetUploadException for a single catch in controllers. */
    public function testAllRejectionsShareTheCommonBaseException(): void
    {
        $upload = $this->createUpload('shell.php', '<?php echo 1; ?>');

        $this->expectException(AssetUploadException::class);

        $this->assetManager->upload($upload);
    }

    // Valid upload and deduplication

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

    /** Duplicate content returns existing Asset without writing to disk again. */
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

    // Broken uploads

    /** PHP upload error must be caught before validation — no partial Asset. */
    public function testUploadWithPhpErrorIsRejected(): void
    {
        $path = $this->writeTempFile('kirik.png', self::REAL_PNG);
        $upload = new UploadedFile($path, 'kirik.png', null, \UPLOAD_ERR_INI_SIZE, true);

        $this->storage->expects(self::never())->method('writeStream');

        $this->expectException(InvalidUploadException::class);

        $this->assetManager->upload($upload);
    }

    /** Empty file is not on the allowlist and must be rejected (fail-closed). */
    public function testEmptyFileIsRejected(): void
    {
        $upload = $this->createUpload('bos.png', '');

        $this->storage->expects(self::never())->method('writeStream');

        $this->expectException(AssetUploadException::class);

        $this->assetManager->upload($upload);
    }

    // Helpers

    private function createUpload(string $clientName, string $contents): UploadedFile
    {
        $path = $this->writeTempFile($clientName, $contents);

        // $test: true skips is_uploaded_file(); MIME null — AssetManager uses finfo.
        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function writeTempFile(string $clientName, string $contents): string
    {
        $path = $this->workDir.'/'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($path, $contents);

        return $path;
    }
}
