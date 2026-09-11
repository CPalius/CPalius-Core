<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

/**
 * One named text format after YAML + optional DB override have been merged.
 *
 * @phpstan-type FilterSpec array{id: string, enabled: bool, weight: int, settings: array<string, mixed>}
 */
final class ResolvedTextFormat
{
    /**
     * @param list<FilterSpec> $filters
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $wysiwyg,
        public readonly array $filters,
    ) {
    }

    public function capability(): string
    {
        return 'text_format.'.$this->id.'.use';
    }

    /**
     * @return list<FilterSpec>
     */
    public function enabledFilters(): array
    {
        $enabled = array_values(array_filter(
            $this->filters,
            static fn (array $row): bool => $row['enabled'] === true && $row['id'] !== '',
        ));
        usort($enabled, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);

        return $enabled;
    }
}
