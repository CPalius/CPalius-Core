<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Queue\Entity\AsyncJob;

/**
 * Enqueue-only contract. Delivery happens in QueueWorker, never in the caller
 * process. Kept separate from the concrete bus so callers stay testable.
 */
interface AsyncJobDispatcherInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $type, array $payload, ?string $tenantId = null, ?\DateTimeImmutable $availableAt = null): AsyncJob;

    public function pendingCount(): int;
}
