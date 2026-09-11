<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\OriginCacheStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * T2.3: CacheTagCollector::setMaxAge() reaches the disk snapshot as a per-file TTL
 * override, independent of the global performance.cpalius.ttl setting.
 */
#[CoversClass(OriginCacheStore::class)]
final class OriginCacheStoreTtlOverrideTest extends TestCase
{
    private string $projectDir;

    private OriginCacheStore $store;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-cache-ttl-test-'.bin2hex(random_bytes(6));
        $this->store = new OriginCacheStore($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    public function testExpiredOverrideDropsTheSnapshotEvenWithinTheGlobalTtl(): void
    {
        $this->store->putHtml('/promo', '', '<html>flash sale</html>', ttlOverride: 1);

        // Global TTL is generous (1 day); the 1-second override must win anyway.
        touch($this->store->htmlPath('/promo', ''), time() - 5);

        self::assertNull($this->store->getHtml('/promo', '', 86400));
    }

    public function testFreshOverrideSurvivesWithinItsWindow(): void
    {
        $this->store->putHtml('/promo', '', '<html>flash sale</html>', ttlOverride: 3600);

        self::assertSame('<html>flash sale</html>', $this->store->getHtml('/promo', '', 86400));
    }

    public function testDeleteExactAlsoRemovesTheTtlSidecar(): void
    {
        $this->store->putHtml('/promo', '', '<html>flash sale</html>', ttlOverride: 3600);
        $ttlFile = $this->store->htmlPath('/promo', '').'.ttl';
        self::assertFileExists($ttlFile);

        $this->store->deleteExact('/promo');

        self::assertFileDoesNotExist($ttlFile);
    }

    public function testWithoutAnOverrideTheGlobalTtlApplies(): void
    {
        $this->store->putHtml('/normal', '', '<html>plain</html>');
        touch($this->store->htmlPath('/normal', ''), time() - 100);

        self::assertNull($this->store->getHtml('/normal', '', 10), 'older than the global TTL, no override to protect it');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}
