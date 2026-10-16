<?php

declare(strict_types=1);

namespace App\Core\Widget;

/**
 * One setting of a widget type. A type lists its fields; the Studio widget editor builds the form from
 * them and validates what comes back (WidgetFieldSanitizer), so a type never writes form or validation code.
 *
 * Types: text, textarea, number, select, checkbox, url, html.
 *   - "html" is rich markup, always passed through the site's HTML sanitiser (scripts are removed).
 *   - "url" accepts http(s) addresses and site-relative paths only.
 *   - "select" takes `options` as value => label (a translation key or plain text).
 */
final readonly class WidgetField
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const NUMBER = 'number';
    public const SELECT = 'select';
    public const CHECKBOX = 'checkbox';
    public const URL = 'url';
    public const HTML = 'html';

    /**
     * @param array<string, string> $options select choices, value => label
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public mixed $default = null,
        public string $help = '',
        public array $options = [],
        public int $min = 0,
        public int $max = 0,
        public bool $translatable = false,
    ) {
    }

    /**
     * The same field, written once per site language in the editor. The type still receives one plain
     * value when it renders: the visitor's language, else the default language, else any language filled in.
     * Works for text, textarea, html and url fields.
     */
    public function translatable(): self
    {
        return new self($this->name, $this->type, $this->label, $this->default, $this->help, $this->options, $this->min, $this->max, true);
    }

    /**
     * Picks the text to show from a stored value: a plain string (not translated) is used as is; a
     * language => text map gives the visitor's language, else the default language, else the first
     * language that was filled in. Empty languages are skipped, so a widget written in one language only
     * shows that text everywhere.
     */
    public static function localized(mixed $value, string $locale, string $default): string
    {
        if (!\is_array($value)) {
            return \is_scalar($value) ? (string) $value : '';
        }
        foreach ([$locale, $default] as $code) {
            if (isset($value[$code]) && \is_string($value[$code]) && trim($value[$code]) !== '') {
                return $value[$code];
            }
        }
        foreach ($value as $text) {
            if (\is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return '';
    }

    public static function text(string $name, string $label, string $default = '', string $help = ''): self
    {
        return new self($name, self::TEXT, $label, $default, $help);
    }

    public static function textarea(string $name, string $label, string $default = '', string $help = ''): self
    {
        return new self($name, self::TEXTAREA, $label, $default, $help);
    }

    public static function html(string $name, string $label, string $default = '', string $help = ''): self
    {
        return new self($name, self::HTML, $label, $default, $help);
    }

    public static function number(string $name, string $label, int $default, int $min = 1, int $max = 30, string $help = ''): self
    {
        return new self($name, self::NUMBER, $label, $default, $help, [], $min, $max);
    }

    public static function checkbox(string $name, string $label, bool $default = true, string $help = ''): self
    {
        return new self($name, self::CHECKBOX, $label, $default, $help);
    }

    public static function url(string $name, string $label, string $default = '', string $help = ''): self
    {
        return new self($name, self::URL, $label, $default, $help);
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, string $label, array $options, string $default = '', string $help = ''): self
    {
        return new self($name, self::SELECT, $label, $default, $help, $options);
    }
}
