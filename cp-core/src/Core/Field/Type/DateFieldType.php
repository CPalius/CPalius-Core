<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

/**
 * Calendar date, stored as an ISO "Y-m-d" string.
 */
#[CpFieldType]
final class DateFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return 'field.type.date';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $dt instanceof \DateTimeImmutable ? $dt->format('Y-m-d') : null;
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
