<?php

declare(strict_types=1);

namespace Modules\Showcase\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Theme-facing helpers. Logic lives in ShowcaseRuntime (lazy-loaded), so a page
 * that never mentions the showcase pays nothing for having the module installed.
 *
 *   {{ cp_showcase_items(6) }}                       latest published entries
 *   {{ cp_showcase_items(4, {featured: true}) }}     promoted entries only
 *   {{ cp_showcase_item(12) }}                       one entry by id, or null
 *   {{ cp_showcase_price(item) }}                    formatted price or null
 *   {{ cp_showcase_cover(item) }}                    cover asset id or null
 *   {{ cp_showcase_url(item) }}                      canonical URL, '' if routed away
 *   {{ cp_showcase_links(item) }}                    resolved cross-module links
 *   {{ cp_showcase_card(item) }}                     rendered card partial
 *   {{ cp_showcase_types() }}                        enabled types for menus
 */
final class ShowcaseExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_showcase_items', [ShowcaseRuntime::class, 'items']),
            new TwigFunction('cp_showcase_item', [ShowcaseRuntime::class, 'item']),
            new TwigFunction('cp_showcase_price', [ShowcaseRuntime::class, 'price']),
            new TwigFunction('cp_showcase_cover', [ShowcaseRuntime::class, 'cover']),
            new TwigFunction('cp_showcase_excerpt', [ShowcaseRuntime::class, 'excerpt']),
            new TwigFunction('cp_showcase_url', [ShowcaseRuntime::class, 'url']),
            new TwigFunction('cp_showcase_links', [ShowcaseRuntime::class, 'links']),
            new TwigFunction('cp_showcase_types', [ShowcaseRuntime::class, 'types']),
            new TwigFunction('cp_showcase_card', [ShowcaseRuntime::class, 'card'], ['is_safe' => ['html']]),
            new TwigFunction('cp_showcase_template', [ShowcaseRuntime::class, 'template']),
            new TwigFunction('showcase_desk_tabs', [ShowcaseRuntime::class, 'deskTabs']),
        ];
    }
}
