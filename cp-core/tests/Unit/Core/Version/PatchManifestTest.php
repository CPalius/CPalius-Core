<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Version;

use App\Core\Version\PatchChecker;
use App\Core\Version\PatchManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is the trust boundary of the patch feature: everything the
 * installer later does is authorised by what this class agreed to accept.
 * Each test below is one way a forged or broken manifest could otherwise get a
 * file written somewhere it should not be.
 */
#[CoversClass(PatchManifest::class)]
final class PatchManifestTest extends TestCase
{
    private const SOURCE = 'https://raw.githubusercontent.com/CPalius/CPalius-Core/v1.1.1/';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function manifest(array $overrides = []): array
    {
        return array_merge([
            'schema' => 1,
            'version' => '1.1.1',
            'base' => '1.1.0',
            'released_at' => '2026-09-20',
            'critical' => false,
            'summary' => 'Bir dosya düzeltildi.',
            'source' => self::SOURCE,
            'files' => [
                ['path' => PatchManifest::VERSION_FILE, 'sha256' => str_repeat('a', 64), 'size' => 1200],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function parse(array $overrides = []): PatchManifest
    {
        return PatchManifest::parse(self::manifest($overrides), PatchChecker::TRUSTED_PREFIXES);
    }

    public function testParsesAWellFormedManifest(): void
    {
        $manifest = self::parse();

        self::assertSame('1.1.1', $manifest->version);
        self::assertSame('1.1.0', $manifest->base);
        self::assertTrue($manifest->appliesTo('1.1.0'));
        self::assertFalse($manifest->appliesTo('1.1.1'));
        self::assertSame(self::SOURCE.PatchManifest::VERSION_FILE, $manifest->urlFor(PatchManifest::VERSION_FILE));
        self::assertSame(1200, $manifest->totalBytes());
    }

    /**
     * The headline rule. Without the version file a patch would apply, leave
     * CpVersion::VERSION where it was, and be offered again on every check for
     * the rest of the installation's life.
     */
    public function testRefusesAPatchThatDoesNotShipTheVersionFile(): void
    {
        $this->expectExceptionMessageMatches('/could not advance the running version/');

        self::parse(['files' => [
            ['path' => 'cp-core/src/Kernel.php', 'sha256' => str_repeat('b', 64), 'size' => 900],
        ]]);
    }

    /**
     * The single most dangerous field: file bodies are fetched from here, so an
     * unvetted host would be remote code execution with extra steps.
     */
    public function testRefusesAnUntrustedSource(): void
    {
        $this->expectExceptionMessageMatches('/not a trusted CPalius location/');

        self::parse(['source' => 'https://raw.githubusercontent.com/attacker/CPalius-Core/main/']);
    }

    public function testRefusesASourceThatIsNotABaseUrl(): void
    {
        $this->expectExceptionMessageMatches('/base URL ending/');

        self::parse(['source' => 'https://raw.githubusercontent.com/CPalius/CPalius-Core/main']);
    }

    public function testRefusesAFileWithoutAUsableDigest(): void
    {
        $this->expectExceptionMessageMatches('/no usable SHA-256/');

        self::parse(['files' => [
            ['path' => PatchManifest::VERSION_FILE, 'size' => 1200],
        ]]);
    }

    public function testRefusesAPathOutsideTheShippableTrees(): void
    {
        $this->expectExceptionMessageMatches('/may not write/');

        self::parse(['files' => [
            ['path' => PatchManifest::VERSION_FILE, 'sha256' => str_repeat('a', 64), 'size' => 1200],
            ['path' => '../../.ssh/authorized_keys', 'sha256' => str_repeat('c', 64), 'size' => 100],
        ]]);
    }

    /**
     * Two entries for one path make the result depend on iteration order, and
     * one of the two orders deletes a file the patch also wanted to write.
     */
    public function testRefusesADuplicatePath(): void
    {
        $this->expectExceptionMessageMatches('/more than once/');

        self::parse(['files' => [
            ['path' => PatchManifest::VERSION_FILE, 'sha256' => str_repeat('a', 64), 'size' => 1200],
            ['path' => PatchManifest::VERSION_FILE, 'action' => 'delete'],
        ]]);
    }

    public function testRefusesAPatchThatDoesNotMoveForward(): void
    {
        $this->expectExceptionMessageMatches('/does not supersede/');

        self::parse(['version' => '1.0.9', 'base' => '1.1.0']);
    }

    public function testRefusesAnUnparsableVersion(): void
    {
        $this->expectExceptionMessageMatches('/unusable "version"/');

        // version_compare() cannot order this, so it would sort wrong against
        // the running version and could announce a downgrade as an upgrade.
        self::parse(['version' => 'v1.1.1-beta']);
    }

    public function testRefusesAnUnknownSchema(): void
    {
        $this->expectExceptionMessageMatches('/schema/');

        self::parse(['schema' => 2]);
    }

    public function testRefusesAnEmptyFileList(): void
    {
        $this->expectExceptionMessageMatches('/lists no files/');

        self::parse(['files' => []]);
    }

    public function testRefusesAnImplausibleFileSize(): void
    {
        $this->expectExceptionMessageMatches('/implausible size/');

        self::parse(['files' => [
            ['path' => PatchManifest::VERSION_FILE, 'sha256' => str_repeat('a', 64), 'size' => PatchManifest::MAX_FILE_BYTES + 1],
        ]]);
    }

    public function testSeparatesWritesFromDeletions(): void
    {
        $manifest = self::parse(['files' => [
            ['path' => PatchManifest::VERSION_FILE, 'sha256' => str_repeat('a', 64), 'size' => 1200],
            ['path' => 'cp-content/modules/Blog/Old.php', 'action' => 'delete'],
        ]]);

        self::assertCount(1, $manifest->writes());
        self::assertSame(['cp-content/modules/Blog/Old.php'], $manifest->deletions());
    }
}
