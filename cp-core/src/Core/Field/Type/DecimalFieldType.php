<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;
use App\Core\Money\DecimalString;

/**
 * Fixed-point decimal, stored as a string to avoid float drift. "scale" setting
 * controls the rounding used on write.
 */
#[CpFieldType]
final class DecimalFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'decimal';
    }

    public function label(): string
    {
        return 'field.type.decimal';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $scale = max(0, min(6, (int) $context->definition->getSetting('scale', 2)));

        // A plain decimal is rounded as text: through a float, a value past ~15 digits or one like 1.005 comes back changed.
        if (\is_int($raw) || (\is_string($raw) && DecimalString::isPlain(trim($raw)))) {
            return DecimalString::round(trim((string) $raw), $scale);
        }

        // Anything else is_numeric() still accepts (1e3, a float) keeps the previous float path.
        return number_format((float) $raw, $scale, '.', '');
    }

    public function indexKind(): ?string
    {
        return 'decimal';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return is_numeric($value) ? (string) $value : null;
    }

    public function settingsSchema(): array
    {
        return [
            ['name' => 'scale', 'type' => 'number', 'label' => 'field.setting.scale', 'default' => 2],
        ];
    }
}
