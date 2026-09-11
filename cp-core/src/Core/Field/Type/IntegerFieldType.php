<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

#[CpFieldType]
final class IntegerFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'integer';
    }

    public function label(): string
    {
        return 'field.type.integer';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        if (!\is_int($value)) {
            return [];
        }

        $violations = [];
        $min = $context->definition->getSetting('min');
        $max = $context->definition->getSetting('max');
        if (is_numeric($min) && $value < (int) $min) {
            $violations[] = 'field.violation.too_small';
        }
        if (is_numeric($max) && $value > (int) $max) {
            $violations[] = 'field.violation.too_large';
        }

        return $violations;
    }

    public function indexKind(): ?string
    {
        return 'int';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return \is_int($value) ? $value : null;
    }

    public function settingsSchema(): array
    {
        return [
            ['name' => 'min', 'type' => 'text', 'label' => 'field.setting.min', 'default' => ''],
            ['name' => 'max', 'type' => 'text', 'label' => 'field.setting.max', 'default' => ''],
        ];
    }
}
