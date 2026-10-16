<?php

declare(strict_types=1);

namespace App\Core\Performance\Metrics;

/**
 * What one request cost: wall time, database time, number of queries.
 *
 * A plain shared counter fed by StatsConnection. It never throws and holds no
 * references to anything, so it is safe to leave on in production — unlike the
 * N+1 tripwire (QueryCounter), which exists to fail.
 *
 * With $trace on (the cp:perf:probe command), every distinct statement is also
 * remembered with its count and time, which is how a repeated query shows up.
 */
final class RequestMetrics
{
    private const MAX_TRACED = 300;

    public int $queries = 0;
    public float $dbSeconds = 0.0;
    public bool $trace = false;

    /** @var array<string, array{count: int, seconds: float}> */
    private array $statements = [];

    public function reset(): void
    {
        $this->queries = 0;
        $this->dbSeconds = 0.0;
        $this->statements = [];
    }

    public function record(string $sql, float $seconds): void
    {
        ++$this->queries;
        $this->dbSeconds += $seconds;

        if (!$this->trace) {
            return;
        }

        $key = self::fingerprint($sql);
        if (!isset($this->statements[$key]) && \count($this->statements) >= self::MAX_TRACED) {
            return;
        }
        $this->statements[$key] ??= ['count' => 0, 'seconds' => 0.0];
        ++$this->statements[$key]['count'];
        $this->statements[$key]['seconds'] += $seconds;
    }

    /** @return array<string, array{count: int, seconds: float}> */
    public function statements(): array
    {
        return $this->statements;
    }

    /** Same statement, different values, one key: literals and IN lists are folded away. */
    public static function fingerprint(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', $sql) ?? $sql;

        return mb_substr($sql, 0, 400);
    }
}
