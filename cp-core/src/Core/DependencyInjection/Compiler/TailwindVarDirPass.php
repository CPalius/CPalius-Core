<?php

declare(strict_types=1);

namespace App\Core\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Pristine Root: redirects symfonycasts/tailwind-bundle compiled CSS cache from project var/tailwind to cp-core/var/tailwind.
 */
final class TailwindVarDirPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('.tailwind.builder')) {
            return;
        }

        $container->getDefinition('.tailwind.builder')
            ->replaceArgument(2, $container->getParameter('kernel.project_dir').'/cp-core/var/tailwind');
    }
}
