<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;

#[CpFieldType]
final class BooleanFieldType extends AbstractFieldType
{
    public static function id(): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return 'field.type.boolean';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        return !\in_array($raw, [0, '0', false, null, '', 'false', 'off', 'no'], true);
    }

    public function indexKind(): ?string
    {
        return 'int';
    }

    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null
    {
        return $value === true ? 1 : 0;
    }

    public function defaultValue(FieldContext $context): mixed
    {
        return (bool) $context->definition->getSetting('default_on', false);
    }

    public function settingsSchema(): array
    {
        return [
            ['name' => 'default_on', 'type' => 'boolean', 'label' => 'field.setting.default_on', 'default' => false],
        ];
    }
}
