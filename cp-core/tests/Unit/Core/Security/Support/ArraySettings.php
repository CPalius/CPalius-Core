<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security\Support;

use App\Core\Settings\SettingsRegistry;

/**
 * In-memory stand-in for the settings registry.
 *
 * The security layer reads its configuration exclusively through
 * SettingsRegistry::get(), so a subclass that answers from an array gives the
 * tests a real object with real types and no database, cache or request stack —
 * the same "real objects, no mocks" discipline the rest of the unit suite uses.
 *
 * The parent constructor is deliberately not called: none of its dependencies is
 * reachable through the two methods overridden here.
 */
final class ArraySettings extends SettingsRegistry
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function all(): array
    {
        return [];
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}
