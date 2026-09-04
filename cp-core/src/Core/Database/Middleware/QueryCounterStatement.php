<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Wrap statement execute() so ORM and native SQL are counted at the DBAL driver layer.
 */
final class QueryCounterStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly QueryCounter $counter,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $table = QueryCounterTableParser::extractTable($this->sql);

        if ($table !== null) {
            $this->counter->increment($table);
        }

        return parent::execute();
    }
}
