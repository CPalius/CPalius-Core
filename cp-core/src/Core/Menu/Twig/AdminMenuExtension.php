<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminMenuExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_admin_menu', [AdminMenuRuntime::class, 'render']),
        ];
    }
}
