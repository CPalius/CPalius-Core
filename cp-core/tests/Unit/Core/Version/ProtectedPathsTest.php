<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Version;

use App\Core\Version\ProtectedPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every case here is a way for remote input to become a write somewhere it
 * should not reach, so the assertions are deliberately blunt: a path is either
 * allowed or it is null, and there is no third answer.
 */
#[CoversClass(ProtectedPaths::class)]
final class ProtectedPathsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'absolute posix' => ['/etc/passwd'];
        yield 'absolute windows' => ['C:/Windows/system32/drivers/etc/hosts'];
        yield 'traversal' => ['cp-core/../../../etc/passwd'];
        yield 'traversal mid-path' => ['cp-core/src/../../secrets.php'];
        yield 'empty' => [''];
        yield 'nul byte' => ["cp-core/src/x.php\0.txt"];

        // The whole point of the uploads exclusion: a release must never be
        // able to drop an executable into the directory that serves user files.
        yield 'uploads' => ['public/uploads/evil.php'];
        yield 'page cache' => ['public/page-cache/index.html'];
        yield 'var' => ['cp-core/var/cache/prod/container.php'];

        // .env holds the database password; overwriting it locks a site out of
        // its own data.
        yield 'active modules' => ['cp-core/config/active_modules.php'];
        yield 'dotenv' => ['.env'];
        yield 'dotenv local' => ['.env.local'];
        yield 'nested dotenv' => ['cp-core/.env.prod'];

        // Composer owns cp-includes/vendor and it is not in version control, so
        // a patch has nowhere to fetch those files from anyway.
        yield 'vendor' => ['cp-includes/vendor/symfony/console/Application.php'];

        // Not one of the trees the package ships.
        yield 'docs' => ['docs/SURUM_YAYINLAMA.md'];
        yield 'unknown root file' => ['phpstan.neon.dist'];
        yield 'dotgit' => ['.git/config'];
    }

    #[DataProvider('refusedPaths')]
    public function testRefusesPathsAPatchMayNotWrite(string $path): void
    {
        self::assertNull(ProtectedPaths::normalizePatchPath($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedPaths(): iterable
    {
        yield 'core source' => ['cp-core/src/Core/Version/CpVersion.php', 'cp-core/src/Core/Version/CpVersion.php'];
        yield 'module' => ['cp-content/modules/Blog/Entity/BlogComment.php', 'cp-content/modules/Blog/Entity/BlogComment.php'];
        yield 'public asset' => ['public/index.php', 'public/index.php'];
        yield 'root composer' => ['composer.json', 'composer.json'];
        yield 'shared host front controller' => ['index.php', 'index.php'];
        yield 'shared host htaccess' => ['.htaccess', '.htaccess'];

        // The one .env-named file that must travel WITH a release: it documents
        // what can be configured, and a site that never receives an updated copy
        // never learns that a new option exists.
        yield 'env example' => ['.env.example', '.env.example'];

        // Backslashes are normalised rather than refused: a publisher on
        // Windows generating a manifest should not ship a broken patch.
        yield 'windows separators' => ['cp-core\\src\\Kernel.php', 'cp-core/src/Kernel.php'];
    }

    #[DataProvider('acceptedPaths')]
    public function testAcceptsAndNormalizesShippablePaths(string $raw, string $expected): void
    {
        self::assertSame($expected, ProtectedPaths::normalizePatchPath($raw));
    }

    public function testIsProtectedAgreesWithTheNormalizer(): void
    {
        // The full updater filters with isProtected() and the patcher with
        // normalizePatchPath(). If those two ever disagree about a path, one of
        // the two writers has a hole the other does not.
        foreach (['public/uploads/a.php', 'cp-core/var/x', '.env', 'cp-core/.env.local'] as $path) {
            self::assertTrue(ProtectedPaths::isProtected($path), $path);
            self::assertNull(ProtectedPaths::normalizePatchPath($path), $path);
        }

        self::assertFalse(ProtectedPaths::isProtected('.env.example'));
        self::assertSame('.env.example', ProtectedPaths::normalizePatchPath('.env.example'));
    }
}
