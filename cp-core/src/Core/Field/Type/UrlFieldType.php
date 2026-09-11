<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

/**
 * Absolute http/https URL. Other schemes (javascript:, data:, file:) are rejected.
 */
#[CpFieldType]
final class UrlFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'url';
    }

    public function label(): string
    {
        return 'field.type.url';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return null;
        }
        if (filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($url, 0, 2000);
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        return \is_string($value) && filter_var($value, \FILTER_VALIDATE_URL) === false
            ? ['field.violation.invalid_url']
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
