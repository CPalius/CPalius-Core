<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Support;

use App\Core\Settings\SettingsRegistry;

/**
 * Settings registry that fails the way an unreachable database does.
 */
final class ExplodingSettings extends SettingsRegistry
{
    public function __construct()
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        throw new \RuntimeException('Database unreachable.');
    }
}
