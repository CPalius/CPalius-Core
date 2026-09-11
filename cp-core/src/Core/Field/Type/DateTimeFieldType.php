<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

/**
 * Date + time, stored as an ISO-8601 string (UTC normalized).
 */
#[CpFieldType]
final class DateTimeFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'datetime';
    }

    public function label(): string
    {
        return 'field.type.datetime';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format(\DATE_ATOM);
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    public function indexKind(): ?string
    {
        return 'datetime';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        if (!\is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
