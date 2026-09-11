<?php

declare(strict_types=1);

namespace App\Core\Field;

/**
 * Contract for a field type — the reusable behaviour shared by every field of
 * that kind. One instance is registered per id (see FieldTypeRegistry). All
 * methods operate on a SINGLE value; cardinality is handled by the caller
 * (FieldValueNormalizer / FieldValidator).
 */
interface FieldTypeInterface
{
    public static function id(): string;

    /** Human label — a translation key. */
    public function label(): string;

    /**
     * Sanitize + coerce one raw submitted value into its stored form.
     * Manifesto Law 5.3: this is the single choke point for value cleaning.
     * Return null to drop the value entirely.
     */
    public function normalize(mixed $raw, FieldContext $context): mixed;

    /**
     * Validate one normalized value.
     *
     * @return list<string> translation keys of violations ([] = valid)
     */
    public function validate(mixed $value, FieldContext $context): array;

    /**
     * Which NodeFieldIndex column a queryable value maps to.
     *
     * @return 'string'|'int'|'decimal'|'datetime'|null null = not indexable
     */
    public function indexKind(): ?string;

    /** Flatten one value for the NodeFieldIndex row. */
    public function indexValue(mixed $value): string|int|float|\DateTimeInterface|null;

    /**
     * Descriptors for the per-field settings sub-form shown in /aacp/fields.
     *
     * @return list<array{name: string, type: string, label: string, default: mixed, choices?: array<string, string>, help?: string}>
     */
    public function settingsSchema(): array;

    /**
     * Sanitize the raw settings map submitted for a FieldDefinition of this type.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $raw): array;

    public function defaultValue(FieldContext $context): mixed;
}
