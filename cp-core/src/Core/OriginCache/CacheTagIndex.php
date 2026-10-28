<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

/**
 * Reverse index: tag → the request paths whose cached snapshot depends on it.
 *
 * Apache serves a snapshot without PHP, so nothing can check a tag version when
 * the page is read (the way Drupal compares tag checksums). Invalidation has to
 * be pushed: when an entity or a setting changes, this index says which files go.
 *
 * Layout: one tiny file per (tag, path) pair under var/origin-tags/<tag-hash>/.
 * Recording is an idempotent create — no read-modify-write, so two requests
 * recording the same tag can never overwrite each other's entry (the previous
 * design kept one serialized list per tag, which lost entries under concurrency,
 * leaving a page that was never purged, and cost O(pages) per page written: ~500 ms
 * per cached page at only 500 pages, measured).
 *
 * Ambient tags. A page carries ~60 `config:*` tags and most of them are touched by
 * every page (site name, security mode, SEO defaults). Indexing those per path is
 * pointless — changing one purges the whole cache either way — and it was what made
 * every page write expensive. Once a config tag has more than AMBIENT_AFTER paths it
 * is marked global: it stops recording paths, and purging it purges everything.
 * Entity and list tags are never made global; they stay exact.
 *
 * The index lives next to the snapshots it describes, on the same node: a page
 * cache that is local to a server needs an index that is local to it too.
 */
final class CacheTagIndex
{
    /** A config tag seen on more paths than this is treated as site-wide. */
    public const AMBIENT_AFTER = 200;

    public function __construct(private readonly OriginCacheStore $store)
    {
    }

    /**
     * @param iterable<string> $tags
     */
    public function record(iterable $tags, string $pathInfo): void
    {
        $name = hash('xxh128', $pathInfo);

        foreach ($tags as $tag) {
            $base = $this->base($tag);
            if (is_file($base.'.global')) {
                continue;
            }

            $dir = $base;
            $file = $dir.\DIRECTORY_SEPARATOR.$name;
            if (is_file($file)) {
                continue;
            }

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                continue;
            }

            if (str_starts_with($tag, 'config:') && $this->count($dir) >= self::AMBIENT_AFTER) {
                $this->makeGlobal($base);

                continue;
            }

            @file_put_contents($file, $pathInfo);
        }
    }

    /** True when changing this tag must purge every snapshot rather than a listed few. */
    public function isGlobal(string $tag): bool
    {
        return is_file($this->base($tag).'.global');
    }

    /**
     * Returns and forgets every path indexed under $tag (the tag is spent once
     * purged — the writer records it again when a page depending on it is next served).
     *
     * @return list<string>
     */
    public function consumePaths(string $tag): array
    {
        $dir = $this->base($tag);
        $paths = [];

        foreach (glob($dir.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            $path = @file_get_contents($file);
            if (\is_string($path) && $path !== '') {
                $paths[] = $path;
            }
            @unlink($file);
        }
        @rmdir($dir);

        return $paths;
    }

    private function makeGlobal(string $base): void
    {
        foreach (glob($base.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($base);
        @file_put_contents($base.'.global', '1');
    }

    private function count(string $dir): int
    {
        return iterator_count(new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS));
    }

    private function base(string $tag): string
    {
        return $this->store->tagsRoot().\DIRECTORY_SEPARATOR.hash('xxh128', $tag);
    }
}
