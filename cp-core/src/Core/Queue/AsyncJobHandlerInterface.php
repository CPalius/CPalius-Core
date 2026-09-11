<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Queue\Entity\AsyncJob;

/**
 * Isolated job handler. Throw to retry; return to ack. Must not leak secrets in exception messages.
 */
interface AsyncJobHandlerInterface
{
    public function supports(string $type): bool;

    public function handle(AsyncJob $job): void;
}
