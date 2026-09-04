<?php

namespace App\Core\Security;

/**
 * Source of truth for valid capability names. Unknown capability = deny (never grant).
 * Authorization uses these strings, not ROLE_* constants.
 */
final class CapabilityRegistry
{
    /** @var array<string, string> capability name => source (e.g. "core", "Modules\\Blog\\BlogModule") */
    private array $capabilities = [];

    /**
     * Idempotent register from core or a module; duplicate names overwrite, they do not throw.
     */
    public function register(string $capability, string $source = 'core'): void
    {
        $this->capabilities[$capability] = $source;
    }

    /**
     * @param iterable<string> $capabilities
     */
    public function registerMany(iterable $capabilities, string $source = 'core'): void
    {
        foreach ($capabilities as $capability) {
            $this->register($capability, $source);
        }
    }

    /**
     * False for unregistered names. CPaliusVoter must call this before treating a capability as real.
     */
    public function has(string $capability): bool
    {
        return isset($this->capabilities[$capability]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->capabilities);
    }

    public function getSource(string $capability): ?string
    {
        return $this->capabilities[$capability] ?? null;
    }
}
