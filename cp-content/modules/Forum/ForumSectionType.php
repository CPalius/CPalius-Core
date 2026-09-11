<?php

declare(strict_types=1);

namespace Modules\Forum;

/**
 * Forum hierarchy: Forum (virtual root) → Division → Category → Subcategory.
 * Topics may be created only in a subcategory.
 */
enum ForumSectionType: string
{
    case Division = 'division';
    case Category = 'category';
    case Subcategory = 'subcategory';

    public function label(): string
    {
        return match ($this) {
            self::Division => 'forum.section_type.division',
            self::Category => 'forum.section_type.category',
            self::Subcategory => 'forum.section_type.subcategory',
        };
    }

    public function isContainer(): bool
    {
        return $this !== self::Subcategory;
    }

    public function allowsTopics(): bool
    {
        return $this === self::Subcategory;
    }

    /** @return list<self> */
    public function allowedParentTypes(): array
    {
        return match ($this) {
            self::Division => [],
            self::Category => [self::Division],
            self::Subcategory => [self::Category, self::Division],
        };
    }
}
