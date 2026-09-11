<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

#[CpFieldType]
final class EmailFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'field.type.email';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        $email = trim((string) $raw);
        if ($email === '') {
            return null;
        }

        return filter_var($email, \FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 180) : $email;
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        return \is_string($value) && filter_var($value, \FILTER_VALIDATE_EMAIL) === false
            ? ['field.violation.invalid_email']
            : [];
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
