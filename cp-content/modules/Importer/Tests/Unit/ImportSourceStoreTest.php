<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Storage\ImportSourceStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportSourceStore::class)]
final class ImportSourceStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cp-imp-src-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        $dir = $this->directory.'/sources';

        if (is_dir($dir)) {
            foreach (glob($dir.'/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        @rmdir($this->directory);
    }

    public function testASuccessfulSourceIsRememberedAndSecretsComeBackOnLoad(): void
    {
        $store = new ImportSourceStore($this->directory);
        $store->save('xenforo', [
            'dbHost' => '127.0.0.1',
            'dbName' => 'xf',
            'dbPassword' => 'secret',
            'prefix' => 'xf_',
        ]);

        self::assertSame('secret', $store->load('xenforo')['dbPassword']);
        self::assertSame('xf', $store->load('xenforo')['dbName']);
    }

    public function testForgetRemovesTheRememberedSource(): void
    {
        $store = new ImportSourceStore($this->directory);
        $store->save('xenforo', ['dbHost' => '127.0.0.1']);
        $store->forget('xenforo');

        self::assertSame([], $store->load('xenforo'));
    }

    public function testEmptyValuesAreNotStored(): void
    {
        $store = new ImportSourceStore($this->directory);
        $store->save('xenforo', ['dbHost' => '127.0.0.1', 'dbPassword' => '']);

        self::assertArrayNotHasKey('dbPassword', $store->load('xenforo'));
    }
}
