<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * One record on its way in, still in source shape.
 *
 * The source id is the only thing the engine insists on. It is what the map
 * table keys on, so it must identify the same record across runs — a WordPress
 * post id, a CSV primary key column, a customer number. Without a stable source
 * id an import cannot be re-run without duplicating everything, which is the
 * failure operators actually hit.
 *
 * The checksum exists so a second run can tell "this row is unchanged" from
 * "this row was edited at the source". It covers the transformed payload, not
 * the raw one: two source shapes that transform to the same thing are the same
 * row as far as the destination is concerned.
 */
final class MigrationRow
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $data,
    ) {
        if (trim($sourceId) === '') {
            throw new \InvalidArgumentException('A migration row needs a non-empty source id; it is the key the map table uses to make re-runs idempotent.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || \is_array($value) || \is_object($value)) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        return new self($this->sourceId, $data);
    }

    /**
     * Stable checksum of the payload, order-insensitive so a source driver that
     * emits columns in a different order does not make every row look changed.
     */
    public function checksum(): string
    {
        $normalized = $this->data;
        $this->ksortRecursive($normalized);

        return hash('xxh128', json_encode($normalized, \JSON_THROW_ON_ERROR | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function ksortRecursive(array &$data): void
    {
        ksort($data);

        foreach ($data as &$value) {
            if (\is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
