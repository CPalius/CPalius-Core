<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Module\ModuleContributionCatalog;
use App\Core\Module\ModuleContributionReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A module may name an exact route the request guard does not scan for a signed-in admin. It must never
 * be able to name a prefix, a pattern, or a path that climbs out of its own area.
 */
#[CoversClass(ModuleContributionReader::class)]
#[CoversClass(ModuleContributionCatalog::class)]
final class WafContentSavePathsTest extends TestCase
{
    public function testExactPathsAreKept(): void
    {
        $catalogue = ModuleContributionReader::normalize(['waf_content_save_paths' => ['/admin/notes/save', '/aacp/x-y_z.1/save']]);

        self::assertSame(['/admin/notes/save', '/aacp/x-y_z.1/save'], $catalogue['waf_content_save_paths']);
    }

    public function testAnythingThatIsNotAPlainPathIsDropped(): void
    {
        $catalogue = ModuleContributionReader::normalize(['waf_content_save_paths' => [
            '/admin/*',            // wildcard
            '/admin/../aacp/x',    // traversal
            'admin/notes/save',    // no leading slash
            '/admin//notes',       // empty segment
            '/admin/notes save',   // whitespace
            "/admin/notes\n/x",    // control character
            '/admin/notes?x=1',    // query string
            '',                    // empty
            42,                    // not a string
            '/'.str_repeat('a', 300), // too long
            '/ok/path',
        ]]);

        self::assertSame(['/ok/path'], $catalogue['waf_content_save_paths']);
    }

    public function testModulesAreMergedWithoutDuplicates(): void
    {
        $a = ModuleContributionReader::normalize(['waf_content_save_paths' => ['/a/save', '/shared/save']]);
        $b = ModuleContributionReader::normalize(['waf_content_save_paths' => ['/shared/save', '/b/save']]);

        $merged = ModuleContributionReader::merge($a, $b);

        self::assertSame(['/a/save', '/shared/save', '/b/save'], $merged['waf_content_save_paths']);
    }

    public function testCatalogExposesThePaths(): void
    {
        $catalog = new ModuleContributionCatalog(['waf_content_save_paths' => ['/a/save']]);

        self::assertSame(['/a/save'], $catalog->wafContentSavePaths());
        self::assertSame([], (new ModuleContributionCatalog([]))->wafContentSavePaths());
    }
}
