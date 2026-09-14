<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\CacheTagIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(CacheTagIndex::class)]
final class CacheTagIndexTest extends TestCase
{
    public function testConsumePathsReturnsEveryRecordedPathForATag(): void
    {
        $index = new CacheTagIndex(new ArrayAdapter());

        $index->record(['node:42', 'list:node:post'], '/blog/post-42');
        $index->record(['list:node:post'], '/blog');

        $postPaths = $index->consumePaths('node:42');
        $listPaths = $index->consumePaths('list:node:post');

        self::assertSame(['/blog/post-42'], $postPaths);
        self::assertSame(['/blog/post-42', '/blog'], $listPaths);
    }

    public function testRecordingTheSamePathTwiceDoesNotDuplicateIt(): void
    {
        $index = new CacheTagIndex(new ArrayAdapter());

        $index->record(['node:42'], '/blog/post-42');
        $index->record(['node:42'], '/blog/post-42');

        self::assertSame(['/blog/post-42'], $index->consumePaths('node:42'));
    }

    public function testConsumingATagForgetsIt(): void
    {
        $index = new CacheTagIndex(new ArrayAdapter());

        $index->record(['node:42'], '/blog/post-42');
        self::assertSame(['/blog/post-42'], $index->consumePaths('node:42'));
        self::assertSame([], $index->consumePaths('node:42'));
    }

    public function testUnknownTagReturnsEmptyList(): void
    {
        $index = new CacheTagIndex(new ArrayAdapter());

        self::assertSame([], $index->consumePaths('node:999'));
    }
}
