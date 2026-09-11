<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

/**
 * Single-line plain text. Tags stripped; length capped by the "max_length" setting.
 */
#[CpFieldType]
final class TextFieldType extends AbstractFieldType
{
    public const DEFAULT_MAX_LENGTH = 255;

    public static function id(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'field.type.text';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        $max = (int) $context->definition->getSetting('max_length', self::DEFAULT_MAX_LENGTH);
        $max = max(1, min(2000, $max));
        $value = trim(strip_tags((string) $raw));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        $max = max(1, min(2000, (int) $context->definition->getSetting('max_length', self::DEFAULT_MAX_LENGTH)));

        return \is_string($value) && mb_strlen($value) > $max ? ['field.violation.too_long'] : [];
    }

    public function indexKind(): ?string
    {
        return 'string';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return \is_string($value) ? mb_substr($value, 0, 255) : null;
    }

    public function settingsSchema(): array
    {
        return [
            ['name' => 'max_length', 'type' => 'number', 'label' => 'field.setting.max_length', 'default' => self::DEFAULT_MAX_LENGTH],
        ];
    }
}
