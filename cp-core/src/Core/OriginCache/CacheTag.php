<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

/**
 * T2.3: naming convention for cache tags, mirroring Drupal's cache tag vocabulary
 * (`node:42`, `node_list`, `config:blog.settings`) without Drupal's rebuild step —
 * tags are pure strings, never pre-registered anywhere.
 */
final class CacheTag
{
    private function __construct()
    {
    }

    public static function entity(string $entityTypeId, int|string $id): string
    {
        return $entityTypeId.':'.$id;
    }

    /**
     * A page that lists entities of a type (optionally scoped to one bundle) depends on
     * this tag instead of every individual item's tag — same trade-off Drupal's own
     * `node_list` tag makes: coarser than per-item, still far narrower than a full purge.
     */
    public static function list(string $entityTypeId, ?string $bundle = null): string
    {
        return $bundle === null
            ? 'list:'.$entityTypeId
            : 'list:'.$entityTypeId.':'.$bundle;
    }

    public static function config(string $key): string
    {
        return 'config:'.$key;
    }
}
