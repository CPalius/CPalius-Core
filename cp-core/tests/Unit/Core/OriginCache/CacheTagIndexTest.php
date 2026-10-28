<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\CacheTagIndex;
use App\Core\OriginCache\OriginCacheStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTagIndex::class)]
final class CacheTagIndexTest extends TestCase
{
    private string $projectDir;

    private OriginCacheStore $store;

    private CacheTagIndex $index;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-tag-index-'.bin2hex(random_bytes(6));
        $this->store = new OriginCacheStore($this->projectDir);
        $this->index = new CacheTagIndex($this->store);
    }

    protected function tearDown(): void
    {
        $this->store->purgeAll();
        @rmdir($this->store->root());
        @rmdir(\dirname($this->store->root()));
        @rmdir($this->projectDir.'/cp-core/var');
        @rmdir($this->projectDir.'/cp-core');
        @rmdir($this->projectDir);
    }

    public function testConsumePathsReturnsEveryRecordedPathForATag(): void
    {
        $this->index->record(['node:42', 'list:node:post'], '/blog/post-42');
        $this->index->record(['list:node:post'], '/blog');

        $postPaths = $this->index->consumePaths('node:42');
        $listPaths = $this->index->consumePaths('list:node:post');

        self::assertSame(['/blog/post-42'], $postPaths);
        sort($listPaths);
        self::assertSame(['/blog', '/blog/post-42'], $listPaths);
    }

    public function testRecordingTheSamePathTwiceDoesNotDuplicateIt(): void
    {
        $this->index->record(['node:42'], '/blog/post-42');
        $this->index->record(['node:42'], '/blog/post-42');

        self::assertSame(['/blog/post-42'], $this->index->consumePaths('node:42'));
    }

    public function testConsumingATagForgetsIt(): void
    {
        $this->index->record(['node:42'], '/blog/post-42');
        self::assertSame(['/blog/post-42'], $this->index->consumePaths('node:42'));
        self::assertSame([], $this->index->consumePaths('node:42'));
    }

    public function testUnknownTagReturnsEmptyList(): void
    {
        self::assertSame([], $this->index->consumePaths('node:999'));
    }

    public function testAConfigTagEveryPageReadsBecomesGlobalAndStopsCostingPerPage(): void
    {
        for ($i = 0; $i <= CacheTagIndex::AMBIENT_AFTER + 5; ++$i) {
            $this->index->record(['config:core.site_name'], '/page-'.$i);
        }

        self::assertTrue($this->index->isGlobal('config:core.site_name'));
        self::assertSame([], $this->index->consumePaths('config:core.site_name'), 'a global tag keeps no per-path list');
        self::assertDirectoryDoesNotExist($this->store->tagsRoot().'/'.hash('xxh128', 'config:core.site_name'));
    }

    public function testARareConfigTagStaysExact(): void
    {
        $this->index->record(['config:blog.hero_title'], '/tr/blog');

        self::assertFalse($this->index->isGlobal('config:blog.hero_title'));
        self::assertSame(['/tr/blog'], $this->index->consumePaths('config:blog.hero_title'));
    }

    public function testEntityAndListTagsAreNeverMadeGlobal(): void
    {
        for ($i = 0; $i <= CacheTagIndex::AMBIENT_AFTER + 50; ++$i) {
            $this->index->record(['list:node:post'], '/page-'.$i);
        }

        self::assertFalse($this->index->isGlobal('list:node:post'));
        self::assertCount(CacheTagIndex::AMBIENT_AFTER + 51, $this->index->consumePaths('list:node:post'));
    }

    public function testPurgingEverythingForgetsTheIndexToo(): void
    {
        $this->index->record(['node:42'], '/blog/post-42');
        $this->store->purgeAll();

        self::assertSame([], $this->index->consumePaths('node:42'), 'an index entry for a file that is gone would only mislead the next purge');
    }
}
