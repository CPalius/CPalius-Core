<?php

declare(strict_types=1);

namespace App\Core\Field\Type;

use App\Core\Field\AbstractFieldType;
use App\Core\Field\Attribute\CpFieldType;
use App\Core\Field\FieldContext;
use App\Core\Field\ReferenceTargetResolver;

/**
 * Stores the integer id of another entity (user, node of a type, or a resource).
 * The "target" setting is an allowlisted string — never a raw class name.
 */
#[CpFieldType]
final class EntityReferenceFieldType extends AbstractFieldType
{
    public function __construct(
        private readonly ReferenceTargetResolver $resolver,
    ) {
    }

    public static function id(): string
    {
        return 'reference';
    }

    public function label(): string
    {
        return 'field.type.reference';
    }

    public function normalize(mixed $raw, FieldContext $context): mixed
    {
        if (\is_array($raw) || \is_object($raw) || $raw === null || $raw === '') {
            return null;
        }

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    public function validate(mixed $value, FieldContext $context): array
    {
        if (!\is_int($value)) {
            return [];
        }

        $target = (string) $context->definition->getSetting('target', '');
        if (!$this->resolver->isValidTarget($target)) {
            return ['field.violation.reference_target_invalid'];
        }

        return $this->resolver->exists($target, $value) ? [] : ['field.violation.reference_missing'];
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
            ['name' => 'target', 'type' => 'text', 'label' => 'field.setting.reference_target', 'default' => 'node', 'help' => 'field.setting.reference_target_help'],
        ];
    }

    public function normalizeSettings(array $raw): array
    {
        $target = trim(strtolower((string) ($raw['target'] ?? 'node')));

        return ['target' => $this->resolver->isValidTarget($target) ? $target : 'node'];
    }
}
