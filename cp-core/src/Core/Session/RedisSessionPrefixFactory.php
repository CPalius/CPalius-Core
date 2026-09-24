<?php

declare(strict_types=1);

namespace App\Core\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * `RedisSessionHandler` defaults to the literal key prefix `sf_s`, identical on
 * every CPalius install. Two sites sharing one Redis server (the shared-VPS
 * deployment model this codebase targets — see cache.yaml's `prefix_seed`
 * comment for the same bug already fixed there) therefore collide on the exact
 * same session-key namespace: `redis-cli KEYS "sf_s*"` on one site enumerates
 * every other site's live sessions (user id, cached capabilities, CSRF token,
 * 2FA-passed flag), and a `FLUSHDB`/backup-restore on one wipes them all.
 *
 * `APP_SECRET` is generated fresh and random per install (EnvironmentWriter::
 * secret()), so it is a free, zero-configuration per-install seed — but unlike
 * cache.yaml's `prefix_seed` (which Symfony hashes internally before using it
 * as a namespace, see CachePoolPass), RedisSessionHandler uses its `prefix`
 * option as a literal, visible Redis key prefix. Passing APP_SECRET straight
 * through would print it in cleartext on every `redis-cli KEYS` listing, which
 * would also hand over the key SettingSecretCodec derives secrets from
 * (SecretBox: sha256(APP_SECRET)). This factory hashes it first, the same way
 * Symfony's own cache layer does, so the prefix is unique per install without
 * ever exposing the secret itself.
 */
final class RedisSessionPrefixFactory
{
    public static function create(\Redis $redis, string $appSecret): RedisSessionHandler
    {
        $prefix = 'sf_s_'.substr(hash('xxh128', $appSecret), 0, 16).'_';

        return new RedisSessionHandler($redis, ['prefix' => $prefix]);
    }
}
