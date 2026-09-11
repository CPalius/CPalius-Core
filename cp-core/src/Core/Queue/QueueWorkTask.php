<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Cron\Attribute\CpCronJob;

/**
 * Ticker only: cron wakes the worker. Jobs themselves are not cron tasks.
 */
final class QueueWorkTask
{
    public function __construct(
        private readonly QueueWorker $queueWorker,
    ) {
    }

    #[CpCronJob(schedule: '* * * * *', name: 'cpalius.queue.work', description: 'Process due async jobs (webhook delivery and inbound handlers)')]
    public function execute(): string
    {
        $stats = $this->queueWorker->run(25);

        return sprintf('processed=%d retried=%d failed=%d', $stats['processed'], $stats['retried'], $stats['failed']);
    }
}
