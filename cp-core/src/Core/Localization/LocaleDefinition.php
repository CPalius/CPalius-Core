<?php

declare(strict_types=1);

namespace App\Core\Localization;

/**
 * Immutable locale DTO cached by LocaleProvider — not the Doctrine Locale entity (unsafe to serialize).
 */
final class LocaleDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $nativeName,
        public readonly bool $isDefault = false,
        public readonly int $sortOrder = 0,
    ) {
    }

    /**
     * @return array{code: string, name: string, nativeName: string, isDefault: bool, sortOrder: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'nativeName' => $this->nativeName,
            'isDefault' => $this->isDefault,
            'sortOrder' => $this->sortOrder,
        ];
    }

    /**
     * Rebuild from a cache row. Missing fields fall back to defaults — never throw on a corrupt cache entry.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            code: (string) ($row['code'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            nativeName: (string) ($row['nativeName'] ?? ''),
            isDefault: (bool) ($row['isDefault'] ?? false),
            sortOrder: (int) ($row['sortOrder'] ?? 0),
        );
    }
}
