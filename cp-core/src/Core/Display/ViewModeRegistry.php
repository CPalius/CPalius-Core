<?php

declare(strict_types=1);

namespace App\Core\Display;

/**
 * Compile-time collected view mode ids (core view_modes.yaml + per-module
 * Resources/config/view_modes.yaml), filled by ViewModeRegistrationPass —
 * same shape as CapabilityRegistry. "default" always exists regardless of
 * what YAML declares: every display lookup falls back to it.
 */
final class ViewModeRegistry
{
    public const DEFAULT = 'default';

    /** @var array<string, string> id => label translation key */
    private array $modes = [self::DEFAULT => 'view_mode.label.default'];

    public function register(string $id, string $label): void
    {
        $this->modes[$id] = $label;
    }

    public function has(string $id): bool
    {
        return isset($this->modes[$id]);
    }

    public function getLabel(string $id): ?string
    {
        return $this->modes[$id] ?? null;
    }

    /**
     * @return array<string, string> id => label translation key
     */
    public function all(): array
    {
        return $this->modes;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->modes);
    }
}
