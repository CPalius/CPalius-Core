<?php

declare(strict_types=1);

namespace App\Core\Performance\Metrics;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Counts and times every statement into RequestMetrics. Observes only: it
 * changes no query, swallows nothing and cannot fail a request.
 */
final class StatsMiddleware implements Middleware
{
    public function __construct(private readonly RequestMetrics $metrics)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $metrics = $this->metrics;

        return new class($driver, $metrics) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly RequestMetrics $metrics)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $metrics = $this->metrics;

                return new class(parent::connect($params), $metrics) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly RequestMetrics $metrics)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $metrics = $this->metrics;

                        return new class(parent::prepare($sql), $metrics, $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly RequestMetrics $metrics, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $t = hrtime(true);
                                try {
                                    return parent::execute();
                                } finally {
                                    $this->metrics->record($this->sql, (hrtime(true) - $t) / 1e9);
                                }
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $t = hrtime(true);
                        try {
                            return parent::query($sql);
                        } finally {
                            $this->metrics->record($sql, (hrtime(true) - $t) / 1e9);
                        }
                    }

                    public function exec(string $sql): int|string
                    {
                        $t = hrtime(true);
                        try {
                            return parent::exec($sql);
                        } finally {
                            $this->metrics->record($sql, (hrtime(true) - $t) / 1e9);
                        }
                    }
                };
            }
        };
    }
}
