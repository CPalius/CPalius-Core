<?php

declare(strict_types=1);

namespace App\Core\Entity\Attribute;

/**
 * Declares a Doctrine entity class as a first-class CPalius entity type, so
 * platform services (Field API now; Revisions, Moderation, generic access and
 * entity queries next) can target it without a concrete class hint.
 *
 * This does NOT replace the Manifesto two-class model — Node and Resource stay
 * the two ready-made profiles. It only names the contract they share so a third
 * kind of entity (a taxonomy Term, an Organization) can opt into the same power.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CpEntityType
{
    /**
     * @param string $id           short unique id ("node", "user", "taxonomy_term")
     * @param string $label        human label — a translation key
     * @param bool   $fieldable    field API can attach definitions to it (implements FieldableInterface)
     * @param bool   $bundleable   true = many bundles (Node::type); false = one bundle equal to $id
     * @param bool   $revisionable Reserved for the revision engine generalization (T1.1 tail).
     * @param bool   $translatable reserved for the translation layer generalization
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $fieldable = true,
        public readonly bool $bundleable = false,
        public readonly bool $revisionable = false,
        public readonly bool $translatable = false,
    ) {
    }
}
