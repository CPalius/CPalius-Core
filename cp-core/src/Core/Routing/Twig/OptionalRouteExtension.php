<?php

declare(strict_types=1);

namespace App\Core\Routing\Twig;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * cp_path_if_exists(): a module's URL, or null when that module is switched off.
 *
 * path() throws when the route does not exist, and a module's routes only
 * exist while the module is active. A theme linking to an optional module
 * (the website theme's Whitepaper buttons) turned every page that drew the
 * link into a 500 on an installation that had not enabled the module.
 */
final class OptionalRouteExtension extends AbstractExtension
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_path_if_exists', $this->pathIfExists(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function pathIfExists(string $name, array $parameters = []): ?string
    {
        try {
            return $this->urls->generate($name, $parameters);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
