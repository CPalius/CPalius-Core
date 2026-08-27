<?php

namespace App\Core\Content;

use App\Repository\NodeRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Başlıktan (locale'e duyarlı transliterasyon ile) benzersiz bir slug
 * üretir. Benzersizlik, Node::slug + Node::locale ikilisine göre
 * kontrol edilir (bkz. NodeRepository::slugExists) — bu, Node entity'sindeki
 * uniq_node_slug_locale kısıtıyla birebir örtüşür.
 *
 * Çakışma durumunda "-2", "-3" gibi sayısal sonekler eklenir (Botble'ın
 * slugs tablosu yerine, burada slug çakışması doğrudan Node tablosu
 * üzerinden kontrol edilir; ayrı bir slug tablosuna ihtiyaç yoktur).
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
