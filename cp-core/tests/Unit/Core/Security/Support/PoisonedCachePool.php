<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache pool that returns one fixed, ill-typed payload for every key.
 */
final class PoisonedCachePool implements CacheItemPoolInterface
{
    /**
     * @param array<mixed> $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function getItem(string $key): CacheItemInterface
    {
        $payload = $this->payload;

        return new class($key, $payload) implements CacheItemInterface {
            /**
             * @param array<mixed> $payload
             */
            public function __construct(private readonly string $key, private readonly array $payload)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function get(): mixed
            {
                return $this->payload;
            }

            public function isHit(): bool
            {
                return true;
            }

            public function set(mixed $value): static
            {
                return $this;
            }

            public function expiresAt(?\DateTimeInterface $expiration): static
            {
                return $this;
            }

            public function expiresAfter(\DateInterval|int|null $time): static
            {
                return $this;
            }
        };
    }

    /**
     * @param list<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return [];
    }

    public function hasItem(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function deleteItem(string $key): bool
    {
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }
}
