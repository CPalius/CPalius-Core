<?php

declare(strict_types=1);

namespace App\Core\Seo;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Optional full <head> SEO renderer. The Seo module implements this; core falls back if absent.
 */
#[AutoconfigureTag('cpalius.seo.head_renderer')]
interface SeoHeadRendererInterface
{
    /**
     * @param array{title?: string, description?: string, robots?: string} $overrides
     */
    public function render(array $overrides = []): string;
}
