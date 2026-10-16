<?php

declare(strict_types=1);

namespace App\Core\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Hands one due job to exactly one worker.
 *
 * Picking due rows with a plain SELECT lets two workers (an overlapping cron run, a second server)
 * take the same job and run it twice, which for "send the webhook" or "create the booking" is a bug.
 * Instead the worker moves the job's available_at forward in a single compare-and-set UPDATE that only
 * succeeds if the row still carries the value the worker saw. Whoever's UPDATE changes a row wins;
 * everyone else sees zero rows and skips the job.
 *
 * The moved available_at is also the lease: a worker that dies mid-job simply lets it expire, and the
 * job becomes due again. Delivery is therefore at-least-once, never twice at the same moment.
 * No schema change: it reuses the column the queue already orders and filters by.
 */
final class AsyncJobLease
{
    /** Long enough for the slowest handler (an outbound HTTP call with retries), short enough to recover from a crash. */
    public const SECONDS = 600;

    public static function tryClaim(Connection $connection, int $jobId, \DateTimeImmutable $seenAvailableAt, \DateTimeImmutable $now): bool
    {
        $until = $now->modify('+'.self::SECONDS.' seconds');

        return 1 === (int) $connection->executeStatement(
            'UPDATE cp_async_jobs SET available_at = :until WHERE id = :id AND processed_at IS NULL AND available_at = :seen',
            ['until' => $until, 'id' => $jobId, 'seen' => $seenAvailableAt],
            ['until' => Types::DATETIME_IMMUTABLE, 'id' => Types::INTEGER, 'seen' => Types::DATETIME_IMMUTABLE],
        );
    }
}
