<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class QueryCounterDriver extends AbstractDriverMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver $driver,
        private readonly QueryCounter $counter,
    ) {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new QueryCounterConnection(parent::connect($params), $this->counter);
    }
}
