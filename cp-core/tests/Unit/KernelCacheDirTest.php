<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Kernel::class)]
final class KernelCacheDirTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['CPALIUS_CACHE_DIR'], $_ENV['CPALIUS_CACHE_DIR']);
        putenv('CPALIUS_CACHE_DIR');
    }

    public function testDefaultCacheDirLivesUnderCpCore(): void
    {
        unset($_SERVER['CPALIUS_CACHE_DIR'], $_ENV['CPALIUS_CACHE_DIR']);
        putenv('CPALIUS_CACHE_DIR');

        self::assertSame(
            '/proj/cp-core/var/cache/dev',
            Kernel::resolveCacheDir('/proj', 'dev'),
        );
    }

    public function testOverrideIsolatesModuleLintFromTheLiveContainer(): void
    {
        $_SERVER['CPALIUS_CACHE_DIR'] = '/proj/cp-core/var/cache/module-lint';

        self::assertSame(
            '/proj/cp-core/var/cache/module-lint',
            Kernel::resolveCacheDir('/proj', 'dev'),
        );
    }
}
