<?php

declare(strict_types=1);

namespace App\Core\Hook\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Registers {{ cp_hook() }}; work lives in HookRuntime. is_safe html — producers own escaping.
 */
final class HookExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_hook', [HookRuntime::class, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
