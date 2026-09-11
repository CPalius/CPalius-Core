<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * T2.3: reverse index tag → request paths that depend on it, backed by the `cache.fragments`
 * pool (Redis/Memcached/filesystem depending on env — see cache.yaml) instead of a new DB
 * table. First real consumer of that pool: it was declared for fragment/ESI caching but sat
 * unused, since CPalius's origin cache is a full-page filesystem snapshot, not ESI (bkz.
 * CPALIUS_YOL_HARITASI.md T2.3 notu — gerçek ESI bilinçli olarak ertelendi).
 */
final class CacheTagIndex
{
    private const KEY_PREFIX = 'cp_cache_tag_';

    public function __construct(
        #[Autowire(service: 'cache.fragments')]
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @param iterable<string> $tags
     */
    public function record(iterable $tags, string $pathInfo): void
    {
        foreach ($tags as $tag) {
            $item = $this->pool->getItem($this->key($tag));
            /** @var list<string> $paths */
            $paths = $item->isHit() ? (array) $item->get() : [];
            if (\in_array($pathInfo, $paths, true)) {
                continue;
            }
            $paths[] = $pathInfo;
            $item->set($paths);
            $this->pool->save($item);
        }
    }

    /**
     * Returns and forgets every path indexed under $tag (the tag is spent once purged —
     * OriginCacheWriter re-populates it the next time a page depending on it is served).
     *
     * @return list<string>
     */
    public function consumePaths(string $tag): array
    {
        $key = $this->key($tag);
        $item = $this->pool->getItem($key);
        /** @var list<string> $paths */
        $paths = $item->isHit() ? (array) $item->get() : [];
        $this->pool->deleteItem($key);

        return array_values(array_map(static fn (mixed $path): string => (string) $path, $paths));
    }

    private function key(string $tag): string
    {
        return self::KEY_PREFIX.hash('xxh128', $tag);
    }
}
