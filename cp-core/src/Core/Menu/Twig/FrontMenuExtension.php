<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * AdminMenuExtension (cp-core/src/Core/Menu/Twig/AdminMenuExtension.php)
 * ile aynı desen: bu sınıf SADECE fonksiyon kaydı yapar, asıl mantık
 * FrontMenuRuntime'da (RuntimeExtensionInterface, lazy-loaded) yaşar.
 */
final class FrontMenuExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_menu', [FrontMenuRuntime::class, 'render']),
        ];
    }
}
