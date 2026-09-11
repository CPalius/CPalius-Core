<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lazily provides the app-cache Redis client (the one behind REDIS_URL), or null
 * when no Redis client library is installed or the connection cannot be opened.
 *
 * Why this exists (Manifesto Law 2.3 — "Core Never Dies"):
 * a plain `#[Autowire(service: 'Redis')]` edge is a HARD container dependency.
 * When the phpredis extension is absent, Symfony still emits a lazy proxy whose
 * generated source reads `class RedisProxy… extends \Redis {}` — loading that
 * file throws `Error: Class "Redis" not found` at *construction* time, before any
 * consumer's try/catch can run. That 500s every route on the consuming controller,
 * including the recovery console (`/aacp/recovery`), which must stay up.
 *
 * Consumers ask this service for a client and treat `null` as "Redis offline".
 * The `Redis` service itself is untouched (prod sessions still use it).
 */
final class OptionalRedis
{
    private bool $resolved = false;
    private ?object $client = null;

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')]
        private readonly string $dsn,
    ) {
    }

    /**
     * A connected phpredis / Relay / Predis client, or null when Redis is
     * unavailable. Memoised for the lifetime of the request (including the null).
     */
    public function get(): ?object
    {
        if ($this->resolved) {
            return $this->client;
        }

        $this->resolved = true;

        if (
            !class_exists(\Redis::class)
            && !class_exists(\Relay\Relay::class)
            && !class_exists(\Predis\Client::class)
        ) {
            return null;
        }

        if (trim($this->dsn) === '') {
            return null;
        }

        try {
            $client = RedisAdapter::createConnection($this->dsn);
        } catch (\Throwable) {
            return null;
        }

        return $this->client = \is_object($client) ? $client : null;
    }

    /**
     * True when a live Redis connection is available right now.
     */
    public function isAvailable(): bool
    {
        $client = $this->get();
        if ($client === null || !method_exists($client, 'ping')) {
            return false;
        }

        try {
            $pong = $client->ping();
        } catch (\Throwable) {
            return false;
        }

        return $pong === true || $pong === '+PONG' || $pong === 'PONG';
    }
}
