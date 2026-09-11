<?php

declare(strict_types=1);

namespace App\Core\Field;

/**
 * Safe defaults for field types: not indexable, no settings, nullable default,
 * always valid. Concrete types override only what they need.
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    public function validate(mixed $value, FieldContext $context): array
    {
        return [];
    }

    public function indexKind(): ?string
    {
        return null;
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return null;
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function normalizeSettings(array $raw): array
    {
        $clean = [];
        foreach ($this->settingsSchema() as $descriptor) {
            $name = $descriptor['name'];
            $raw[$name] ??= $descriptor['default'];
            $clean[$name] = $this->coerceSetting($descriptor['type'], $raw[$name], $descriptor);
        }

        return $clean;
    }

    public function defaultValue(FieldContext $context): mixed
    {
        return null;
    }

    /**
     * @param array{name: string, type: string, label: string, default: mixed, choices?: array<string, string>, help?: string} $descriptor
     */
    protected function coerceSetting(string $type, mixed $value, array $descriptor): mixed
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'number' => is_numeric($value) ? (str_contains((string) $value, '.') ? (float) $value : (int) $value) : $descriptor['default'],
            'select' => \in_array((string) $value, array_keys($descriptor['choices'] ?? []), true) ? (string) $value : (string) $descriptor['default'],
            'textarea' => mb_substr(trim(strip_tags((string) $value)), 0, 5000),
            default => mb_substr(trim(strip_tags((string) $value)), 0, 255),
        };
    }

    /**
     * Small helper for scalar-string normalization shared by several types.
     */
    protected function trimmedString(mixed $raw, int $maxLength): ?string
    {
        $value = trim(strip_tags((string) $raw));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
