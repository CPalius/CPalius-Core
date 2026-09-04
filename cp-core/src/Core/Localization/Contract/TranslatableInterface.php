<?php

declare(strict_types=1);

namespace App\Core\Localization\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Translation-group contract: one row per locale, siblings share translation_group_id (not an FK).
 * Null group is valid (ungrouped). Implement via TranslatableTrait; Node already has the same pattern.
 */
interface TranslatableInterface
{
    /**
     * Locale code of this row (e.g. "tr").
     */
    public function getLocale(): string;

    /**
     * Shared group id, or null when the row is not in a group.
     */
    public function getTranslationGroupId(): ?Uuid;

    /**
     * Put this row in a new empty group (first language). Overwrites an existing group.
     */
    public function assignToNewTranslationGroup(): static;

    /**
     * Attach this row to an existing group (e.g. add an EN sibling to a TR category).
     */
    public function joinTranslationGroup(Uuid $translationGroupId): static;

    /**
     * Detach from the group. Sibling locales are unchanged.
     */
    public function leaveTranslationGroup(): static;

    /**
     * Create a group if missing, otherwise return the current one (entry for "add a translation").
     */
    public function ensureTranslationGroup(): Uuid;
}
