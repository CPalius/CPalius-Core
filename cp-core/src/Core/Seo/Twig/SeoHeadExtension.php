<?php

declare(strict_types=1);

namespace App\Core\Seo\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Front-office {{ cp_seo_head() }}. Work lives in SeoHeadRuntime (lazy).
 */
final class SeoHeadExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_seo_head', [SeoHeadRuntime::class, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
