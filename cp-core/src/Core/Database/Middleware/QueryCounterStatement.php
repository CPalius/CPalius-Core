<?php

declare(strict_types=1);

namespace App\Core\Database\Middleware;

use App\Core\Database\QueryCounter;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Hazırlanmış bir (prepared) statement'ın execute() çağrısını sarmalar.
 * NodeIndexListener/NodeRepository gibi Doctrine ORM üzerinden atılan
 * TÜM sorgular (SELECT/INSERT/UPDATE/DELETE), DBAL seviyesinde bu
 * statement'lardan geçer — bu yüzden ORM'e özel bir hook (postLoad vb.)
 * yerine DBAL Driver seviyesinde ölçüm yapmak, hem native SQL hem de
 * QueryBuilder ile atılan sorguları TEK noktadan yakalar.
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
