<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\CacheTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTag::class)]
final class CacheTagTest extends TestCase
{
    public function testEntityTagFormat(): void
    {
        self::assertSame('node:42', CacheTag::entity('node', 42));
        self::assertSame('taxonomy_term:und-general', CacheTag::entity('taxonomy_term', 'und-general'));
    }

    public function testListTagWithAndWithoutBundle(): void
    {
        self::assertSame('list:node', CacheTag::list('node'));
        self::assertSame('list:node:post', CacheTag::list('node', 'post'));
    }

    public function testConfigTagFormat(): void
    {
        self::assertSame('config:blog.settings', CacheTag::config('blog.settings'));
    }
}
