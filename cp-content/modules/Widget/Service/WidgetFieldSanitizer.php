<?php

declare(strict_types=1);

namespace Modules\Widget\Service;

use App\Core\Content\RichTextSanitizer;
use App\Core\Widget\WidgetField;

/**
 * Turns what a widget form posted into clean settings, field by field, from the type's own field list.
 * Anything not declared by the type is dropped, so a hand-edited request cannot smuggle extra keys into
 * the stored settings, and every value is coerced to the field's type and limits.
 */
final class WidgetFieldSanitizer
{
    private const TEXT_MAX = 255;

    private const TEXTAREA_MAX = 5000;

    private const HTML_MAX = 20000;

    public function __construct(
        private readonly RichTextSanitizer $richText,
    ) {
    }

    /**
     * @param list<WidgetField>    $fields
     * @param array<string, mixed> $posted
     * @param list<string>         $locales site language codes; a translatable field posts one value per code
     *
     * @return array<string, mixed> a translatable field becomes a language => text map holding only the
     *                              languages that were filled in (a plain string is kept as is)
     */
    public function sanitize(array $fields, array $posted, array $locales = []): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $raw = $posted[$field->name] ?? null;
            if ($field->translatable && \is_array($raw)) {
                $map = [];
                foreach ($locales as $code) {
                    $text = $this->scalar($field, $raw[$code] ?? null);
                    if ($text !== '') {
                        $map[$code] = $text;
                    }
                }
                $clean[$field->name] = $map;
                continue;
            }
            $clean[$field->name] = $this->scalar($field, $raw);
        }

        return $clean;
    }

    /**
     * Stored values as the editor needs them: a translatable field always as a map with one entry per
     * language (a plain stored string counts as the default language's text).
     *
     * @param list<WidgetField>    $fields
     * @param array<string, mixed> $values from withDefaults()
     * @param list<string>         $locales
     *
     * @return array<string, mixed>
     */
    public function forForm(array $fields, array $values, array $locales, string $default): array
    {
        foreach ($fields as $field) {
            if (!$field->translatable) {
                continue;
            }
            $value = $values[$field->name] ?? '';
            $map = [];
            foreach ($locales as $code) {
                $map[$code] = \is_array($value) ? (string) ($value[$code] ?? '') : ($code === $default ? (string) $value : '');
            }
            $values[$field->name] = $map;
        }

        return $values;
    }

    /**
     * Stored values with every translatable field reduced to the one text for this visitor's language,
     * so a widget type never has to know about languages.
     *
     * @param list<WidgetField>    $fields
     * @param array<string, mixed> $values from withDefaults()
     *
     * @return array<string, mixed>
     */
    public function localize(array $fields, array $values, string $locale, string $default): array
    {
        foreach ($fields as $field) {
            if ($field->translatable) {
                $values[$field->name] = WidgetField::localized($values[$field->name] ?? '', $locale, $default);
            }
        }

        return $values;
    }

    private function scalar(WidgetField $field, mixed $raw): string|int|bool
    {
        return match ($field->type) {
            WidgetField::CHECKBOX => $raw !== null && $raw !== '0',
            WidgetField::NUMBER => $this->number($field, $raw),
            WidgetField::SELECT => $this->select($field, $raw),
            WidgetField::URL => $this->url($raw),
            WidgetField::HTML => $this->html($raw),
            WidgetField::TEXTAREA => mb_substr(trim(\is_string($raw) ? $raw : ''), 0, self::TEXTAREA_MAX),
            default => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(\is_string($raw) ? $raw : '')) ?? ''), 0, self::TEXT_MAX),
        };
    }

    /**
     * Stored values with each field's default filled in where the stored one is missing, for rendering
     * and for the edit form (a field added to a type later has no stored value yet).
     *
     * @param list<WidgetField>    $fields
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function withDefaults(array $fields, array $stored): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field->name] = \array_key_exists($field->name, $stored) ? $stored[$field->name] : $field->default;
        }

        return $values;
    }

    private function number(WidgetField $field, mixed $raw): int
    {
        $value = is_numeric($raw) ? (int) $raw : (int) $field->default;
        $min = $field->min;
        $max = $field->max > 0 ? $field->max : \PHP_INT_MAX;

        return max($min, min($max, $value));
    }

    private function select(WidgetField $field, mixed $raw): string
    {
        $value = \is_string($raw) ? $raw : '';

        return \array_key_exists($value, $field->options) ? $value : (string) $field->default;
    }

    /**
     * http(s) addresses and site-relative paths; anything else (javascript:, data:, //host) becomes empty.
     */
    private function url(mixed $raw): string
    {
        $url = trim(\is_string($raw) ? $raw : '');
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return mb_substr($url, 0, 500);
        }

        return preg_match('~^https?://[^\s<>"\']+$~i', $url) === 1 ? mb_substr($url, 0, 500) : '';
    }

    private function html(mixed $raw): string
    {
        $html = mb_substr(trim(\is_string($raw) ? $raw : ''), 0, self::HTML_MAX);

        return $html === '' ? '' : $this->richText->sanitize($html);
    }
}
