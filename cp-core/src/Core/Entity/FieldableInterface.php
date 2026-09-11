<?php

declare(strict_types=1);

namespace App\Core\Entity;

/**
 * A domain object that the Field API can attach definitions to and store values on.
 *
 * Implemented by App\Entity\Node (bundle = Node::type) and App\Entity\User
 * (single bundle "user"). Modules may implement it on their own entities to opt
 * their records into the shared field system.
 *
 * The Field API operates only through these methods — it never type-hints a
 * concrete entity class, so field types, widgets, formatters and the flat index
 * work the same for every fieldable entity.
 */
interface FieldableInterface
{
    /**
     * The #[CpEntityType] id this object belongs to ("node", "user", …).
     * Used to look up the entity-type definition and to key the flat field index.
     */
    public function fieldableEntityTypeId(): string;

    /**
     * The bundle field definitions are bound to. For Node this is Node::type
     * ("post", "page"); for single-bundle entities it equals the entity-type id.
     */
    public function fieldableBundle(): string;

    /**
     * Content locale used as normalization / validation context (date parsing,
     * reference target resolution, …). Entities that are not localized return
     * the ISO 639-2 "und" (undetermined) marker.
     */
    public function fieldableLocale(): string;

    /**
     * The raw value bag that also stores field values. This bag may hold
     * non-field keys (module meta, SEO, …); the Field API only ever reads and
     * writes keys backed by a FieldDefinition and leaves the rest untouched.
     *
     * @return array<string, mixed>
     */
    public function getFieldableData(): array;

    /**
     * Replace the raw value bag. Callers merge field values into the array
     * returned by getFieldableData() and pass the whole bag back here.
     *
     * @param array<string, mixed> $data
     */
    public function setFieldableData(array $data): void;
}
