<?php

declare(strict_types=1);

namespace App\Core\Rebuild;

use App\Core\Cache\CacheRebuildManager;

/**
 * Platform-level cache recount: app pool, Doctrine SQL cache, origin HTML.
 * AACP only — wiping the compiled container is not a Studio operator action.
 */
final class CoreCacheRebuilder implements RebuilderInterface
{
    public function __construct(
        private readonly CacheRebuildManager $cacheRebuildManager,
    ) {
    }

    public function getId(): string
    {
        return 'core.caches';
    }

    public function getName(): string
    {
        return 'core.rebuild.caches';
    }

    public function getDescription(): string
    {
        return 'core.rebuild.caches_desc';
    }

    public function getBatchSize(): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 90;
    }

    public function getTotal(): int
    {
        return 1;
    }

    public function isStudioVisible(): bool
    {
        return false;
    }

    public function rebuild(int $offset, int $limit): int
    {
        unset($limit);
        if ($offset > 0) {
            return 0;
        }

        $this->cacheRebuildManager->clearSymfonyCache();

        return 1;
    }
}
