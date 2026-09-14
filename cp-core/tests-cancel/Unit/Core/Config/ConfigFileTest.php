<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Config;

use App\Core\Config\ConfigFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigFile::class)]
final class ConfigFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/cp_cfg_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    public function testWriteIsDeterministicAndReadsBack(): void
    {
        $file = new ConfigFile($this->dir);

        $file->write('settings', ['z' => 1, 'a' => ['n' => 2, 'm' => 3]]);
        $first = file_get_contents($file->path('settings'));

        $file->write('settings', ['a' => ['m' => 3, 'n' => 2], 'z' => 1]);
        self::assertSame($first, file_get_contents($file->path('settings')), 'key order must not matter');

        self::assertSame(['a' => ['m' => 3, 'n' => 2], 'z' => 1], $file->read('settings'));
    }

    public function testListOnDiskFindsNestedYaml(): void
    {
        mkdir($this->dir.'/workflow');
        file_put_contents($this->dir.'/settings.yaml', "a: 1\n");
        file_put_contents($this->dir.'/field.page.yaml', "a: 1\n");
        file_put_contents($this->dir.'/workflow/editorial.yaml', "a: 1\n");
        file_put_contents($this->dir.'/notes.txt', 'ignore me');

        self::assertSame(['field.page', 'settings', 'workflow/editorial'], (new ConfigFile($this->dir))->listOnDisk());

        array_map('unlink', glob($this->dir.'/workflow/*') ?: []);
        rmdir($this->dir.'/workflow');
    }
}
