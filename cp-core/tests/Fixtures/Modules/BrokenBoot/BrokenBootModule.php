<?php

declare(strict_types=1);

namespace Modules\BrokenBoot;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Intentionally broken user-space module — passes static checks but throws in boot(); lives under Modules\ for isolation.
 * Registered only in autoload-dev so production module discovery stays clean.
 */
final class BrokenBootModule extends Bundle
{
    /** Exact quarantine log message asserted by ModuleIsolationTest. */
    public const FAILURE_MESSAGE = 'BrokenBootModule kasitli olarak boot() sirasinda patladi.';

    public function boot(): void
    {
        throw new \RuntimeException(self::FAILURE_MESSAGE);
    }
}
