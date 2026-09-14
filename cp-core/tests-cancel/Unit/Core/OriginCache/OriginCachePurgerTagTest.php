<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\CacheTagIndex;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\OriginCache\OriginCacheStore;
use App\Repository\LocaleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * T2.3 kanıtı: bir tag'i purge etmek, sadece o tag altında kayıtlı sayfaları düşürür —
 * aynı store'daki ilgisiz bir sayfa dokunulmadan kalır (eski `purgeAreas()`'ın aksine).
 */
#[CoversClass(OriginCachePurger::class)]
final class OriginCachePurgerTagTest extends TestCase
{
    private string $projectDir;

    private OriginCacheStore $store;

    private CacheTagIndex $tagIndex;

    private OriginCachePurger $purger;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-cache-tag-test-'.bin2hex(random_bytes(6));
        $this->store = new OriginCacheStore($this->projectDir);
        $this->tagIndex = new CacheTagIndex(new ArrayAdapter());
        $this->purger = new OriginCachePurger($this->store, $this->fakeLocaleProvider(), $this->tagIndex);
    }

    private function fakeLocaleProvider(): LocaleProvider
    {
        return new LocaleProvider(
            $this->createMock(LocaleRepository::class),
            new ArrayAdapter(),
            'en',
            'en',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    public function testPurgeTagsDropsOnlyThePagesRecordedUnderThatTag(): void
    {
        $this->store->putHtml('/blog/post-42', '', '<html>post 42</html>');
        $this->tagIndex->record(['node:42', 'list:node:post'], '/blog/post-42');

        $this->store->putHtml('/blog', '', '<html>index</html>');
        $this->tagIndex->record(['list:node:post'], '/blog');

        $this->store->putHtml('/forum', '', '<html>forum untouched</html>');

        $deleted = $this->purger->purgeTags('node:42');

        self::assertSame(1, $deleted);
        self::assertNull($this->store->getHtml('/blog/post-42', '', 0), 'the edited node\'s own page must drop');
        self::assertNotNull($this->store->getHtml('/blog', '', 0), 'a listing page not tagged node:42 must survive');
        self::assertNotNull($this->store->getHtml('/forum', '', 0), 'an unrelated module page must never be touched');
    }

    public function testPurgeTagsConsumesTheIndexSoASecondPurgeIsANoop(): void
    {
        $this->store->putHtml('/blog/post-42', '', '<html>post 42</html>');
        $this->tagIndex->record(['node:42'], '/blog/post-42');

        self::assertSame(1, $this->purger->purgeTags('node:42'));
        self::assertSame(0, $this->purger->purgeTags('node:42'));
    }

    public function testPurgeTagsWithNoTagsIsANoop(): void
    {
        self::assertSame(0, $this->purger->purgeTags());
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
