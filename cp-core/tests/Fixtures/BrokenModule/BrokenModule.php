<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\BrokenModule;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Intentionally broken test bundle under App\Tests\Fixtures — NOT isolated (proves namespace boundary).
 * Used by ModuleIsolationTest to verify core bundle boot errors still propagate.
 */
final class BrokenModule extends Bundle
{
    public const FAILURE_MESSAGE = 'BrokenModule kasitli olarak boot() sirasinda patladi.';

    public function boot(): void
    {
        throw new \RuntimeException(self::FAILURE_MESSAGE);
    }
}
