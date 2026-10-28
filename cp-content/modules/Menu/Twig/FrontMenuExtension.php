<?php

declare(strict_types=1);

namespace Modules\Menu\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FrontMenuExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_menu', [FrontMenuRuntime::class, 'render']),
            new TwigFunction('cp_menu_location', [MenuLocationRuntime::class, 'render'], ['is_safe' => ['html']]),
            new TwigFunction('cp_menu_location_items', [MenuLocationRuntime::class, 'items']),
        ];
    }
}
