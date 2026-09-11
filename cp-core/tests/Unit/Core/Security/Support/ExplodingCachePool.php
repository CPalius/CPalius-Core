<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache pool that fails the way an unreachable Redis does.
 */
final class ExplodingCachePool implements CacheItemPoolInterface
{
    public function getItem(string $key): CacheItemInterface
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    /**
     * @param list<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function hasItem(string $key): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function clear(): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function deleteItem(string $key): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function deleteItems(array $keys): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function save(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }

    public function commit(): bool
    {
        throw new \RuntimeException('Cache backend unreachable.');
    }
}
