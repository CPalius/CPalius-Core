<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class QueryCounterConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly QueryCounter $counter,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new QueryCounterStatement(parent::prepare($sql), $this->counter, $sql);
    }

    public function query(string $sql): Result
    {
        $table = QueryCounterTableParser::extractTable($sql);

        if ($table !== null) {
            $this->counter->increment($table);
        }

        return parent::query($sql);
    }
}
