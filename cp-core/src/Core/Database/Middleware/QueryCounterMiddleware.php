<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Law 6.1 N+1 guard entry. Registered only in when@dev — zero cost in prod.
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
