<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Performance;

use App\Core\Performance\Metrics\RequestMetrics;
use App\Core\Performance\Metrics\RouteStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(RouteStats::class)]
#[CoversClass(RequestMetrics::class)]
final class RouteStatsTest extends TestCase
{
    public function testAveragesPercentilesAndOrdering(): void
    {
        $stats = new RouteStats(new ArrayAdapter());
        foreach ([10, 10, 10, 10, 10, 10, 10, 10, 10, 900] as $ms) {
            $stats->add('home', 'guest', (float) $ms, 5, 2.0, 40 * 1048576);
        }
        $stats->add('forum', 'member', 300.0, 40, 90.0, 64 * 1048576);

        $report = $stats->report();

        self::assertSame(['forum', 'home'], array_column($report, 'route'), 'slowest average first');
        $home = $report[1];
        self::assertSame(10, $home['n']);
        self::assertEqualsWithDelta(99.0, $home['avgMs'], 0.01);
        self::assertSame(900.0, $home['msMax']);
        self::assertEqualsWithDelta(5.0, $home['avgQ'], 0.001);
        self::assertSame(1600, $home['p95Ms'], 'p95 of ten samples is the tenth: the one slow request');
        self::assertSame(25, RouteStats::percentile([0 => 9, 6 => 1], 0.5), 'the median of the same samples is fast');
        self::assertSame(3200, RouteStats::percentile([0 => 1, 7 => 9], 0.5), 'slower than every bucket reports the open-ended top');
    }

    public function testResetForgetsEverything(): void
    {
        $stats = new RouteStats(new ArrayAdapter());
        $stats->add('home', 'guest', 10.0, 1, 1.0, 1);
        $stats->reset();

        self::assertSame([], $stats->report());
    }

    public function testFingerprintFoldsValuesSoRepeatedShapesCollapse(): void
    {
        $a = RequestMetrics::fingerprint("SELECT * FROM t WHERE id = 5 AND name = 'a''b' AND x IN (1, 2, 3)");
        $b = RequestMetrics::fingerprint("SELECT *  FROM t WHERE id = 99 AND name = 'zzz' AND x IN (7, 8)");

        self::assertSame($a, $b);
    }

    public function testTracingIsOffUnlessAskedFor(): void
    {
        $m = new RequestMetrics();
        $m->record('SELECT 1', 0.001);
        self::assertSame(1, $m->queries);
        self::assertSame([], $m->statements());

        $m->trace = true;
        $m->record('SELECT 1', 0.001);
        $m->record('SELECT 2', 0.001);
        self::assertSame(2, $m->statements()['SELECT ?']['count']);
    }
}
