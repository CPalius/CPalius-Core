<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

/**
 * Multi-line plain text. Tags stripped, newlines kept, capped at 20k chars.
 */
#[CpFieldType]
final class TextareaFieldType extends AbstractFieldType
{
    public const MAX_LENGTH = 20000;

    public static function id(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'field.type.textarea';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        $value = trim(strip_tags((string) $raw));
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_LENGTH);
    }

    public function indexKind(): ?string
    {
        return 'string';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return \is_string($value) ? mb_substr($value, 0, 255) : null;
    }
}
