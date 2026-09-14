<?php

declare(strict_types=1);

namespace Modules\Healthy;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Healthy user-space module fixture — control group for isolation tests (must boot alongside a broken sibling).
 * Static boot counter proves boot() ran; resetBootCount() is called from test setUp.
 */
final class HealthyModule extends Bundle
{
    /** Static counter because Kernel owns bundle instances; tests cannot access the live object. */
    private static int $bootCount = 0;

    public function boot(): void
    {
        parent::boot();

        ++self::$bootCount;
    }

    public static function bootCount(): int
    {
        return self::$bootCount;
    }

    public static function resetBootCount(): void
    {
        self::$bootCount = 0;
    }
}
