<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Media;

use App\Core\Localization\LocaleProvider;
use App\Core\Media\AssetUrlGenerator;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The CDN switch rewrites the URL of every image on the public site, so the
 * tests that matter are the ones about NOT rewriting: a malformed base, a
 * disabled switch, and a path that is not an upload all have to fall back to
 * the origin rather than emit something broken.
 */
#[CoversClass(AssetUrlGenerator::class)]
final class AssetUrlGeneratorTest extends TestCase
{
    /**
     * @param array<string, string> $values
     */
    private function generator(array $values): AssetUrlGenerator
    {
        $settingRepository = $this->createMock(SettingRepository::class);
        $settingRepository->method('findAllAsMap')->willReturn($values);

        $localeRepository = $this->createMock(LocaleRepository::class);
        $localeRepository->method('findActive')->willReturn([]);

        $registry = new SettingsRegistry(
            $settingRepository,
            new LocaleProvider($localeRepository, new ArrayAdapter(), 'tr', 'tr'),
            new RequestStack(),
            new ArrayAdapter(),
        );

        foreach ([
            ['cdn.enabled', 'checkbox', false],
            ['cdn.base_url', 'text', ''],
            ['cdn.images_only', 'checkbox', true],
        ] as [$key, $type, $default]) {
            $registry->addDefinition(new SettingDefinition($key, $key, $type, $default, [], 'storage', 'cdn'));
        }

        return new AssetUrlGenerator($registry);
    }

    public function testFallsBackToLocalWhenTheCdnIsOff(): void
    {
        $urls = $this->generator(['cdn.enabled' => '0', 'cdn.base_url' => 'https://cdn.example.com/uploads']);

        self::assertSame('/uploads/2026/09/a.jpg', $urls->forKey('2026/09/a.jpg'));
        self::assertFalse($urls->isCdnEnabled());
    }

    public function testRewritesToThePullZoneBase(): void
    {
        $urls = $this->generator([
            'cdn.enabled' => '1',
            'cdn.base_url' => 'https://cdn.example.com/uploads',
        ]);

        self::assertSame('https://cdn.example.com/uploads/2026/09/a.jpg', $urls->forKey('2026/09/a.jpg'));
    }

    /**
     * The base REPLACES "/uploads", which is what lets one setting serve both a
     * pull zone in front of this origin and a public bucket whose objects are
     * rooted at the top.
     */
    public function testRewritesToABucketRootWithoutAnUploadsSegment(): void
    {
        $urls = $this->generator([
            'cdn.enabled' => '1',
            'cdn.base_url' => 'https://pub-abc123.r2.dev',
        ]);

        self::assertSame('https://pub-abc123.r2.dev/2026/09/a.jpg', $urls->forKey('2026/09/a.jpg'));
    }

    public function testAcceptsAKeyThatIsAlreadyAnUploadsUrl(): void
    {
        $urls = $this->generator(['cdn.enabled' => '1', 'cdn.base_url' => 'https://cdn.example.com/uploads']);

        self::assertSame('https://cdn.example.com/uploads/2026/09/a.jpg', $urls->forKey('/uploads/2026/09/a.jpg'));
    }

    /**
     * rewrite() is called on ImageProcessor output, which may already be an
     * absolute URL on a second pass. Rewriting twice would produce a URL with
     * the CDN host inside its own path.
     */
    public function testRewriteLeavesNonUploadUrlsAlone(): void
    {
        $urls = $this->generator(['cdn.enabled' => '1', 'cdn.base_url' => 'https://cdn.example.com/uploads']);

        self::assertSame('https://cdn.example.com/uploads/x.jpg', $urls->rewrite('https://cdn.example.com/uploads/x.jpg'));
        self::assertSame('/assets/app.css', $urls->rewrite('/assets/app.css'));
        self::assertSame('', $urls->rewrite(''));
    }

    public function testImagesOnlyLeavesDocumentsOnTheOrigin(): void
    {
        $urls = $this->generator([
            'cdn.enabled' => '1',
            'cdn.base_url' => 'https://cdn.example.com/uploads',
            'cdn.images_only' => '1',
        ]);

        self::assertSame('https://cdn.example.com/uploads/2026/09/a.jpg', $urls->forKey('2026/09/a.jpg'));

        // A PDF served from a CDN hostname has left public/uploads/.htaccess
        // behind, and with it whatever that file was doing about access.
        self::assertSame('/uploads/2026/09/rapor.pdf', $urls->forKey('2026/09/rapor.pdf'));
    }

    public function testImagesOnlyOffSendsEverythingToTheCdn(): void
    {
        $urls = $this->generator([
            'cdn.enabled' => '1',
            'cdn.base_url' => 'https://cdn.example.com/uploads',
            'cdn.images_only' => '0',
        ]);

        self::assertSame('https://cdn.example.com/uploads/2026/09/rapor.pdf', $urls->forKey('2026/09/rapor.pdf'));
    }

    /**
     * A bad base is refused outright rather than normalised. The cost of being
     * strict is one error message; the cost of being lenient is every image on
     * the site pointing somewhere that does not resolve.
     */
    public function testRefusesABaseThatIsNotPlainHttps(): void
    {
        foreach (['http://cdn.example.com', '//cdn.example.com', 'cdn.example.com', 'javascript:alert(1)', ''] as $base) {
            $urls = $this->generator(['cdn.enabled' => '1', 'cdn.base_url' => $base]);

            self::assertSame('/uploads/a.jpg', $urls->forKey('a.jpg'), $base);
            self::assertFalse($urls->isCdnEnabled(), $base);
        }
    }

    public function testRefusesUnsafeKeys(): void
    {
        $urls = $this->generator(['cdn.enabled' => '1', 'cdn.base_url' => 'https://cdn.example.com/uploads']);

        self::assertSame('', $urls->forKey(null));
        self::assertSame('', $urls->forKey(''));
        self::assertSame('', $urls->forKey('../../.env'));
        self::assertSame('', $urls->forKey("a\0.jpg"));
    }

    public function testTrailingSlashesInTheBaseDoNotDoubleUp(): void
    {
        $urls = $this->generator(['cdn.enabled' => '1', 'cdn.base_url' => 'https://cdn.example.com/uploads/']);

        self::assertSame('https://cdn.example.com/uploads/a.jpg', $urls->forKey('a.jpg'));
    }
}
