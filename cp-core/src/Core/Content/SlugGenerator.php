<?php

namespace App\Core\Content;

use App\Repository\NodeRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Generates a unique slug from a title with locale-aware transliteration.
 * Uniqueness is checked on Node::slug + Node::locale (matches uniq_node_slug_locale); conflicts get -2, -3 suffixes.
 */
final class SlugGenerator
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    public function generate(string $title, string $locale, ?int $excludeId = null): string
    {
        $slugger = new AsciiSlugger($locale);
        $baseSlug = strtolower($slugger->slug($title)->toString());

        if ($baseSlug === '') {
            $baseSlug = 'n-'.substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->nodeRepository->slugExists($slug, $locale, $excludeId)) {
            $slug = $baseSlug.'-'.$suffix;
            ++$suffix;
        }

        return $slug;
    }
}
