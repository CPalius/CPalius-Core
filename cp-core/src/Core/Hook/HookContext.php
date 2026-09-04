<?php

declare(strict_types=1);

namespace App\Core\Hook;

/**
 * Bag passed into a hook point and returned after the chain (set/get, fluent).
 */
final class HookContext
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, mixed> $initial
     */
    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): self
    {
        unset($this->data[$key]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Append HTML for {{ cp_hook() }}; later listeners accumulate, they do not overwrite.
     */
    public function appendHtml(string $html): self
    {
        $this->data['__html_output'] = ($this->data['__html_output'] ?? '').$html;

        return $this;
    }

    public function getHtml(): string
    {
        $value = $this->data['__html_output'] ?? '';

        return is_string($value) ? $value : '';
    }
}
