<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Source\Wordpress\WordpressOrigin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WordpressOrigin::class)]
final class WordpressOriginTest extends TestCase
{
    public function testASqlExtensionIsADump(): void
    {
        self::assertTrue(WordpressOrigin::looksLikeSqlDump('/tmp/export.sql'));
        self::assertTrue(WordpressOrigin::looksLikeSqlDump('/tmp/export.sql.gz'));
    }

    public function testAnXmlFileIsNotADump(): void
    {
        $path = sys_get_temp_dir().'/cpalius-wxr-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, "<?xml version=\"1.0\"?>\n<rss></rss>");

        try {
            self::assertFalse(WordpressOrigin::looksLikeSqlDump($path));
        } finally {
            @unlink($path);
        }
    }
}
