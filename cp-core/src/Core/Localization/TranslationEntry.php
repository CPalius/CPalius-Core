<?php

declare(strict_types=1);

namespace App\Core\Localization;

/**
 * One Translation Explorer row: key, file group, and locale => value (empty string if untranslated).
 */
final class TranslationEntry
{
    /**
     * @param array<string, string> $values locale => translation (empty string if missing)
     */
    public function __construct(
        public readonly string $group,
        public readonly string $key,
        public readonly array $values = [],
    ) {
    }

    public function valueFor(string $locale): string
    {
        return $this->values[$locale] ?? '';
    }

    /**
     * True when any of $locales is blank — used by the AACP "missing translations" filter.
     *
     * @param list<string> $locales
     */
    public function isIncompleteFor(array $locales): bool
    {
        foreach ($locales as $locale) {
            if (trim($this->valueFor($locale)) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $locales
     *
     * @return array{group: string, key: string, values: array<string, string>, incomplete: bool}
     */
    public function toArray(array $locales = []): array
    {
        $values = [];

        foreach ($locales === [] ? array_keys($this->values) : $locales as $locale) {
            $values[$locale] = $this->valueFor($locale);
        }

        return [
            'group' => $this->group,
            'key' => $this->key,
            'values' => $values,
            'incomplete' => $this->isIncompleteFor($locales === [] ? array_keys($this->values) : $locales),
        ];
    }
}
