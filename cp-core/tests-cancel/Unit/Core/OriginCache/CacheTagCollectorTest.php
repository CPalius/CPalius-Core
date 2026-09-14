<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\OriginCache;

use App\Core\OriginCache\CacheTagCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheTagCollector::class)]
final class CacheTagCollectorTest extends TestCase
{
    public function testTagsAreDeduplicated(): void
    {
        $collector = new CacheTagCollector();
        $collector->addTag('node:42');
        $collector->addTag('node:42');
        $collector->addTags(['node:42', 'list:node:post']);

        self::assertSame(['node:42', 'list:node:post'], $collector->getTags());
    }

    public function testEntityAndListConvenienceHelpers(): void
    {
        $collector = new CacheTagCollector();
        $collector->addEntityTag('node', 42);
        $collector->addListTag('node', 'post');
        $collector->addListTag('node');

        self::assertSame(['node:42', 'list:node:post', 'list:node'], $collector->getTags());
    }

    public function testMaxAgeStartsUnsetAndOnlyEverShrinks(): void
    {
        $collector = new CacheTagCollector();
        self::assertNull($collector->getMaxAge());

        $collector->setMaxAge(3600);
        self::assertSame(3600, $collector->getMaxAge());

        $collector->setMaxAge(60);
        self::assertSame(60, $collector->getMaxAge(), 'a later, shorter cap must win');

        $collector->setMaxAge(3600);
        self::assertSame(60, $collector->getMaxAge(), 'a later, longer value must not extend an existing cap');
    }
}
