<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;
use App\Core\TextFormat\TextFormatAccess;
use App\Core\TextFormat\TextFormatProcessor;
use App\Core\TextFormat\TextFormatRegistry;

/**
 * WYSIWYG / markdown HTML. Named text format is stored WITH the value
 * (`{value, format}`) so output uses the same pipeline that sanitized on
 * write (T2.5). Legacy plain strings are treated as basic_html.
 *
 * Format access is re-checked on every write: a member cannot persist
 * full_html by crafting the POST (Drupal keeps the stored format even after
 * the role loses access to it).
 */
#[CpFieldType]
final class RichTextFieldType extends AbstractFieldType
{
    public const MAX_LENGTH = 200000;

    public function __construct(
        private readonly TextFormatProcessor $processor,
        private readonly TextFormatAccess $access,
        private readonly TextFormatRegistry $formats,
    ) {
    }

    public static function id(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'field.type.rich_text';
    }

    public function settingsSchema(): array
    {
        return [
            [
                'name' => 'default_format',
                'type' => 'select',
                'label' => 'field.setting.default_format',
                'default' => TextFormatRegistry::BASIC_HTML,
                'choices' => $this->formats->choices(),
                'help' => 'field.setting.default_format.help',
            ],
            [
                'name' => 'allowed_formats',
                'type' => 'text',
                'label' => 'field.setting.allowed_formats',
                'default' => '',
                'help' => 'field.setting.allowed_formats.help',
            ],
        ];
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        [$html, $requested] = self::extract($raw);
        if (trim($html) === '') {
            return null;
        }

        $html = mb_substr($html, 0, self::MAX_LENGTH);
        $allowlist = self::parseAllowlist($context->definition->getSetting('allowed_formats', ''));
        $fallback = (string) $context->definition->getSetting('default_format', TextFormatRegistry::BASIC_HTML);
        $format = $this->access->resolve($requested !== '' ? $requested : $fallback, $fallback, $allowlist);

        $stored = $this->processor->sanitizeForStorage($html, $format);
        if (trim(strip_tags($stored)) === '' && trim($stored) === '') {
            return null;
        }

        return ['value' => $stored, 'format' => $format];
    }

    public function indexKind(): ?string
    {
        return 'string';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        [$html] = self::extract($value);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    /**
     * @return array{0: string, 1: string} value, format
     */
    public static function extract(mixed $stored): array
    {
        if (\is_array($stored)) {
            return [
                (string) ($stored['value'] ?? ''),
                (string) ($stored['format'] ?? ''),
            ];
        }

        return [\is_string($stored) ? $stored : '', ''];
    }

    /**
     * @return list<string>
     */
    public static function parseAllowlist(mixed $raw): array
    {
        if (\is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[,\s]+/', (string) $raw) ?: [];
        }

        $out = [];
        foreach ($parts as $id) {
            if (\is_string($id) && preg_match(TextFormatRegistry::ID_PATTERN, $id) === 1) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }
}
