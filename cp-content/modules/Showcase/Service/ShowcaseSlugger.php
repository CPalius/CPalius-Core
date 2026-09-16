<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use Modules\Showcase\Repository\ShowcaseItemRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Slug generation for showcase items — the same shape as the core SlugGenerator,
 * but checked against cp_showcase_items instead of cp_nodes, because uniqueness
 * here is (slug, locale) on this module's own table (Law 5.2).
 */
final class ShowcaseSlugger
{
    private const MAX_LENGTH = 180;

    public function __construct(
        private readonly ShowcaseItemRepository $items,
    ) {
    }

    /**
     * @param string $preferred an operator-supplied slug; blank falls back to the title
     */
    public function generate(string $title, string $locale, ?int $excludeId = null, string $preferred = ''): string
    {
        $source = trim($preferred) !== '' ? $preferred : $title;
        $slugger = new AsciiSlugger($locale);
        $base = mb_substr(strtolower($slugger->slug($source)->toString()), 0, self::MAX_LENGTH);

        if ($base === '') {
            // A title made only of characters the slugger drops (emoji, CJK with
            // no transliteration) still needs a usable, unguessable URL.
            $base = 's-'.substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $slug = $base;
        $suffix = 2;

        while ($this->items->slugExists($slug, $locale, $excludeId)) {
            $slug = $base.'-'.$suffix;
            ++$suffix;
        }

        return $slug;
    }
}
