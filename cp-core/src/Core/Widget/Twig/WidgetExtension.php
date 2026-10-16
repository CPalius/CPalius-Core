<?php

declare(strict_types=1);

namespace App\Core\Widget\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ cp_widget_area('blog_sidebar', {page: 'post', subject: post}) }}
 * is_safe ['html']: the HTML is produced by widget types, which escape their own output.
 */
final class WidgetExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_widget_area', [WidgetRuntime::class, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
