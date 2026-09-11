<?php

declare(strict_types=1);

namespace App\Core\Module\DependencyInjection\Compiler;

use App\Core\Module\ModuleContributionReader;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects Resources/config/contributions.yaml from every booted module bundle.
 */
final class ModuleContributionPass implements CompilerPassInterface
{
    public const CONTAINER_PARAMETER = 'cpalius.module.contributions';

    public function process(ContainerBuilder $container): void
    {
        $merged = ModuleContributionReader::empty();

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleMeta) {
            $namespace = (string) ($bundleMeta['namespace'] ?? '');
            if (!str_starts_with($namespace, 'Modules\\')) {
                continue;
            }

            $file = rtrim((string) $bundleMeta['path'], '/\\').'/Resources/config/contributions.yaml';
            if (!is_file($file)) {
                continue;
            }

            $container->addResource(new FileResource($file));
            $merged = ModuleContributionReader::merge($merged, ModuleContributionReader::fromFile($file));
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $merged);
    }
}
