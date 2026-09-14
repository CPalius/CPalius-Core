<?php

declare(strict_types=1);

namespace App\Core\Version\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Same Extension/Runtime split as ReadingTimeExtension: registration here,
 * logic in the lazy-loaded runtime.
 *
 * The laziness is the point, not a style choice. cp_release_status() reads the
 * settings map; a template that never calls it — every front-end page that does
 * not draw an update badge — must not pay for the service being constructed.
 */
final class VersionExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_version', [VersionRuntime::class, 'version']),
            new TwigFunction('cp_release_status', [VersionRuntime::class, 'releaseStatus']),
        ];
    }
}
