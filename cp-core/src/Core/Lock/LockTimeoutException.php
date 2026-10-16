<?php

declare(strict_types=1);

namespace App\Core\Lock;

/**
 * The named lock was not free within the time the caller was willing to wait.
 */
final class LockTimeoutException extends \RuntimeException
{
    public function __construct(public readonly string $lockName)
    {
        parent::__construct(sprintf('Could not acquire lock "%s" in time.', $lockName));
    }
}
