<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Law 6.1 N+1 guard entry.
 *
 * NOT free in prod by default, despite what this comment used to claim.
 * DoctrineBundle autoconfigures every Driver\Middleware implementation with
 * the doctrine.middleware tag, and App\ picks this class up — so it was armed
 * in production regardless of the when@dev-only tag in services.yaml. There it
 * threw inside live requests, OriginCacheWriter swallowed the exception as
 * "Origin cache write failed", and the origin cache silently never wrote.
 *
 * services.yaml now sets autoconfigure: false on this class and grants the tag
 * explicitly under when@dev / when@test. Do not re-enable autoconfigure here.
 */
final class QueryCounterMiddleware implements Middleware
{
    public function __construct(
        private readonly QueryCounter $counter,
    ) {
    }

    /**
     * Skip wrapping on CLI: migrations and cache:warmup are batch queries, not N+1.
     */
    public function wrap(Driver $driver): Driver
    {
        if (\PHP_SAPI === 'cli') {
            return $driver;
        }

        return new QueryCounterDriver($driver, $this->counter);
    }
}
