<?php

declare(strict_types=1);

namespace App\Core\Plugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Same pattern as FrontMenuExtension: registers cp_plugin only; logic lives in PluginRuntime (lazy-loaded).
 * is_safe ['html']: output comes from plugin Twig templates, not user input (Manifesto Law 5.3).
 */
final class PluginExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_plugin', [PluginRuntime::class, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
