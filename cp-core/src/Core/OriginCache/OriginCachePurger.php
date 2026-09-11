<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Localization\LocaleProvider;

/**
 * Drops stale HTML snapshots after public content or layout changes.
 */
final class OriginCachePurger
{
    public function __construct(
        private readonly OriginCacheStore $store,
        private readonly LocaleProvider $localeProvider,
        private readonly CacheTagIndex $tagIndex,
    ) {
    }

    public function purgeAll(): int
    {
        return $this->store->purgeAll();
    }

    public function purgeAreas(string ...$areas): void
    {
        if ($areas === []) {
            return;
        }

        foreach ($this->localeProvider->getCodes() as $locale) {
            foreach ($areas as $area) {
                if ($area === 'home') {
                    $this->store->deleteExact('/'.$locale);
                    $this->store->deleteExact('/');
                    continue;
                }
                $this->store->deletePrefix($locale.'/'.$area);
            }
        }
    }

    /**
     * T2.3: drops only the snapshots recorded as depending on each tag — the primitive
     * that replaces per-controller `purgeAreas()` guesswork with precise, automatic
     * invalidation (see EntityCacheTagInvalidator, wired to the T1.5 entity events).
     */
    public function purgeTags(string ...$tags): int
    {
        if ($tags === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_unique($tags) as $tag) {
            foreach ($this->tagIndex->consumePaths($tag) as $pathInfo) {
                $deleted += $this->store->deleteExact($pathInfo);
            }
        }

        return $deleted;
    }
}
