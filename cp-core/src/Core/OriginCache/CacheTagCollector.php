<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

/**
 * T2.3: a controller declares what the CURRENT response depends on ("this page shows
 * node 42 and the post list"); OriginCacheWriter reads it back at the end of the request
 * to index the snapshot. One instance per request (default container sharing) — a
 * classic PHP-per-request process never leaks state into the next request, so this
 * needs no reset-between-requests plumbing.
 */
final class CacheTagCollector
{
    /** @var array<string, true> */
    private array $tags = [];

    private ?int $maxAge = null;

    public function addTag(string $tag): void
    {
        $this->tags[$tag] = true;
    }

    /**
     * @param iterable<string> $tags
     */
    public function addTags(iterable $tags): void
    {
        foreach ($tags as $tag) {
            $this->addTag($tag);
        }
    }

    public function addEntityTag(string $entityTypeId, int|string $id): void
    {
        $this->addTag(CacheTag::entity($entityTypeId, $id));
    }

    public function addListTag(string $entityTypeId, ?string $bundle = null): void
    {
        $this->addTag(CacheTag::list($entityTypeId, $bundle));
    }

    public function addConfigTag(string $key): void
    {
        $this->addTag(CacheTag::config($key));
    }

    /**
     * Caps this response's snapshot lifetime below the global TTL (e.g. a flash-sale
     * page); the smallest value wins so a caller can only shorten, never extend.
     */
    public function setMaxAge(int $seconds): void
    {
        $this->maxAge = $this->maxAge === null ? $seconds : min($this->maxAge, $seconds);
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }
}
