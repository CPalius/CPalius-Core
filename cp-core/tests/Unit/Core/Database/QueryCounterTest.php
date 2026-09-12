<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Database;

use App\Core\Database\MaxQueriesExceededException;
use App\Core\Database\QueryCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryCounter::class)]
final class QueryCounterTest extends TestCase
{
    public function testIgnoresInformationSchemaCatalogQueries(): void
    {
        $counter = new QueryCounter(maxQueriesPerTable: 2);

        for ($i = 0; $i < 20; ++$i) {
            $counter->increment('information_schema');
            $counter->increment('INFORMATION_SCHEMA');
        }

        self::assertSame([], $counter->getCounts());
    }

    public function testStillTripsOnApplicationTables(): void
    {
        $counter = new QueryCounter(maxQueriesPerTable: 2);
        $counter->increment('cp_nodes');
        $counter->increment('cp_nodes');

        $this->expectException(MaxQueriesExceededException::class);
        $counter->increment('cp_nodes');
    }
}
