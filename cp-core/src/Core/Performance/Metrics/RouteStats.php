<?php

declare(strict_types=1);

namespace App\Core\Performance\Metrics;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-route running totals of what requests cost, kept in the application cache.
 *
 * One cache item per route and audience, so requests for different pages never
 * contend. Updates are read-modify-write without a lock: under heavy concurrency
 * an occasional sample is lost, which for averages and percentiles over thousands
 * of samples is noise — and a lock on the hot path would cost more than the
 * measurement is worth. Sampling (see RouteStatsSubscriber) keeps the write rate
 * low in the first place.
 */
final class RouteStats
{
    /** Upper bounds in ms for the latency histogram; the last bucket is "slower than all of these". */
    public const BUCKETS = [25, 50, 100, 200, 400, 800, 1600];

    private const INDEX = 'cp_route_stats_index';
    private const PREFIX = 'cp_route_stats_';
    private const TTL = 14 * 86400;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function add(string $route, string $audience, float $ms, int $queries, float $dbMs, int $memoryBytes): void
    {
        $key = $this->key($route, $audience);
        $item = $this->cache->getItem($key);
        /** @var array<string, mixed> $row */
        $row = $item->isHit() && \is_array($item->get()) ? $item->get() : $this->empty($route, $audience);

        ++$row['n'];
        $row['ms'] += $ms;
        $row['msMax'] = max($row['msMax'], $ms);
        $row['q'] += $queries;
        $row['qMax'] = max($row['qMax'], $queries);
        $row['db'] += $dbMs;
        $row['mem'] = max($row['mem'], $memoryBytes);
        $row['at'] = time();
        $slot = \count(self::BUCKETS);
        foreach (self::BUCKETS as $i => $limit) {
            if ($ms <= $limit) {
                $slot = $i;
                break;
            }
        }
        $row['hist'][$slot] = ($row['hist'][$slot] ?? 0) + 1;

        $this->cache->save($item->set($row)->expiresAfter(self::TTL));

        if ($row['n'] === 1) {
            $index = $this->cache->getItem(self::INDEX);
            $keys = $index->isHit() && \is_array($index->get()) ? $index->get() : [];
            $keys[$key] = true;
            $this->cache->save($index->set($keys)->expiresAfter(self::TTL));
        }
    }

    /**
     * Slowest first, by mean time.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $rows = [];
        foreach ($this->keys() as $key) {
            $item = $this->cache->getItem($key);
            if (!$item->isHit() || !\is_array($item->get()) || ($item->get()['n'] ?? 0) < 1) {
                continue;
            }
            $r = $item->get();
            $rows[] = $r + [
                'avgMs' => $r['ms'] / $r['n'],
                'avgQ' => $r['q'] / $r['n'],
                'avgDbMs' => $r['db'] / $r['n'],
                'p95Ms' => self::percentile($r['hist'] ?? [], 0.95),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['avgMs'] <=> $a['avgMs']);

        return $rows;
    }

    public function reset(): void
    {
        foreach ($this->keys() as $key) {
            $this->cache->deleteItem($key);
        }
        $this->cache->deleteItem(self::INDEX);
    }

    /**
     * Upper bound (ms) of the bucket the percentile falls in — an estimate that is
     * exact to the bucket width, which is all a histogram promises.
     *
     * @param array<int, int> $hist
     */
    public static function percentile(array $hist, float $p): int
    {
        $total = array_sum($hist);
        if ($total === 0) {
            return 0;
        }
        $need = (int) ceil($total * $p);
        $seen = 0;
        foreach (range(0, \count(self::BUCKETS)) as $i) {
            $seen += $hist[$i] ?? 0;
            if ($seen >= $need) {
                return self::BUCKETS[$i] ?? 3200;
            }
        }

        return 3200;
    }

    /** @return list<string> */
    private function keys(): array
    {
        $index = $this->cache->getItem(self::INDEX);

        return $index->isHit() && \is_array($index->get()) ? array_keys($index->get()) : [];
    }

    private function key(string $route, string $audience): string
    {
        return self::PREFIX.preg_replace('/[^A-Za-z0-9_.]/', '_', $route).'_'.$audience;
    }

    /** @return array<string, mixed> */
    private function empty(string $route, string $audience): array
    {
        return ['route' => $route, 'audience' => $audience, 'n' => 0, 'ms' => 0.0, 'msMax' => 0.0, 'q' => 0, 'qMax' => 0, 'db' => 0.0, 'mem' => 0, 'at' => 0, 'hist' => []];
    }
}
