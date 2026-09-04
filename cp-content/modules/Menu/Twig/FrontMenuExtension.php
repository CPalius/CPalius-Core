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
        ];
    }
}
