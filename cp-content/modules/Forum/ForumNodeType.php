<?php

declare(strict_types=1);

namespace Modules\Forum;

/**
 * Forum node_type: category (container), forum (topic leaf), or external link.
 */
enum ForumNodeType: string
{
    case Category = 'category';
    case Forum = 'forum';
    case Link = 'link';

    public static function fromSectionType(ForumSectionType $sectionType): self
    {
        return match ($sectionType) {
            ForumSectionType::Division, ForumSectionType::Category => self::Category,
            ForumSectionType::Subcategory => self::Forum,
        };
    }

    public function isContainer(): bool
    {
        return $this === self::Category;
    }

    public function allowsThreads(): bool
    {
        return $this === self::Forum;
    }

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Kategori',
            self::Forum => 'Forum',
            self::Link => 'Baglanti',
        };
    }
}
