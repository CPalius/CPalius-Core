<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Twig render hook for module plugins via {{ cp_plugin('name') }}. Core never imports a module plugin class.
 * Plugin/*.php classes must implement this interface or ModulePackageContract refuses the package.
 */
#[AutoconfigureTag('cpalius.module_plugin')]
interface PluginInterface
{
    /** Unique id used by PluginRegistry and {{ cp_plugin('name') }} (e.g. blog_widget). */
    public function getName(): string;

    /** Human-readable label shown in AACP module-plugin lists. */
    public function getLabel(): string;

    /**
     * @param array<string, mixed> $context Twig-supplied keys; plugins must fail safe on missing values
     */
    public function render(array $context = []): string;

    /** Return false to skip rendering (empty string). Inactive modules never register. */
    public function isActive(): bool;
}
