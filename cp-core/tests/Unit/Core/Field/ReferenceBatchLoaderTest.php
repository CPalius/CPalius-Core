<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Field;

use App\Core\Field\Display\ReferenceBatchLoader;
use App\Core\Field\ReferenceTargetResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Manifesto Law 6.1: N references cost one query, not N.
 */
#[CoversClass(ReferenceBatchLoader::class)]
final class ReferenceBatchLoaderTest extends TestCase
{
    public function testCollectedIdsResolveInASingleLoad(): void
    {
        $resolver = $this->createMock(ReferenceTargetResolver::class);
        $resolver->expects(self::once())
            ->method('load')
            ->with('user', self::callback(static fn (array $ids): bool => sort($ids) && $ids === [1, 2, 3]))
            ->willReturn([1 => (object) ['id' => 1], 2 => (object) ['id' => 2], 3 => (object) ['id' => 3]]);

        $loader = new ReferenceBatchLoader($resolver);
        $loader->collect('user', 1);
        $loader->collect('user', 2);
        $loader->collect('user', 3);

        self::assertNotNull($loader->get('user', 1));
        self::assertNotNull($loader->get('user', 2));
        self::assertNotNull($loader->get('user', 3));
    }

    public function testMissingIdReturnsNullAndIsCached(): void
    {
        $resolver = $this->createMock(ReferenceTargetResolver::class);
        $resolver->expects(self::once())->method('load')->willReturn([]);

        $loader = new ReferenceBatchLoader($resolver);
        $loader->collect('user', 99);

        self::assertNull($loader->get('user', 99));
        self::assertNull($loader->get('user', 99)); // no second load
    }

    public function testResetClearsState(): void
    {
        $resolver = $this->createMock(ReferenceTargetResolver::class);
        $resolver->expects(self::exactly(2))->method('load')->willReturn([5 => (object) ['id' => 5]]);

        $loader = new ReferenceBatchLoader($resolver);
        $loader->get('user', 5);
        $loader->reset();
        $loader->get('user', 5);
    }
}
