<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Source\Wordpress\UploadsResolver;
use Modules\Importer\Source\Wordpress\UrlPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadsResolver::class)]
#[CoversClass(UrlPath::class)]
final class UploadsResolverTest extends TestCase
{
    private const UPLOADS = __DIR__.'/../Fixtures/uploads';

    private function resolver(): UploadsResolver
    {
        return new UploadsResolver(self::UPLOADS);
    }

    public function testResolvesAnAttachmentUrlToTheLocalFile(): void
    {
        $path = $this->resolver()->resolve('https://eski.example/wp-content/uploads/2024/03/foto.png');

        self::assertNotNull($path);
        self::assertFileExists($path);
        self::assertStringEndsWith('2024/03/foto.png', str_replace('\\', '/', $path));
    }

    /**
     * The same file lives under many prefixes over a site's life — http and
     * https, with and without www, behind a CDN. Matching the uploads-relative
     * tail survives all of them; matching whole URLs would not.
     */
    #[DataProvider('equivalentUrls')]
    public function testTheAbsolutePrefixDoesNotMatter(string $url): void
    {
        self::assertNotNull($this->resolver()->resolve($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentUrls(): iterable
    {
        yield 'https' => ['https://eski.example/wp-content/uploads/2024/03/foto.png'];
        yield 'http' => ['http://eski.example/wp-content/uploads/2024/03/foto.png'];
        yield 'www' => ['https://www.eski.example/wp-content/uploads/2024/03/foto.png'];
        yield 'protocol relative' => ['//cdn.eski.example/wp-content/uploads/2024/03/foto.png'];
        yield 'root relative' => ['/wp-content/uploads/2024/03/foto.png'];
        yield 'moved uploads folder' => ['https://eski.example/files/2024/03/foto.png'];
        yield 'percent encoded' => ['https://eski.example/wp-content/uploads/2024/03/foto%2Epng'];
    }

    public function testAFileThatIsNotThereResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve('https://eski.example/wp-content/uploads/2024/03/yok.png'));
    }

    /**
     * The export is a file somebody else wrote. An attachment URL that climbs
     * out of the uploads directory must not turn the importer into a way to
     * read whatever the web user can read.
     */
    public function testAPathEscapingTheUploadsDirectoryIsRefused(): void
    {
        // Resolves to a file that really exists one level above the uploads
        // root, so this exercises the containment check rather than merely
        // landing on something that is not there.
        self::assertFileExists(self::UPLOADS.'/../sample-export.xml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the uploads directory/');

        $this->resolver()->resolve('https://eski.example/wp-content/uploads/../sample-export.xml');
    }

    public function testAnEscapingPathThatPointsAtNothingIsSimplyNotFound(): void
    {
        // Also safe, by the other branch: realpath() fails, so there is nothing
        // to compare and nothing to read.
        self::assertNull($this->resolver()->resolve('https://eski.example/wp-content/uploads/../../../../nope.json'));
    }

    public function testAMissingUploadsDirectoryIsRefusedWithTheFlagToFixIt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/-o uploads=/');

        new UploadsResolver(sys_get_temp_dir().'/cpalius-no-such-uploads-'.bin2hex(random_bytes(4)));
    }

    public function testAUrlWithNoRecognisableUploadsPathIsNull(): void
    {
        self::assertNull((new UrlPath())->relative('https://eski.example/hakkimizda'));
        self::assertNull((new UrlPath())->relative(''));
    }
}
