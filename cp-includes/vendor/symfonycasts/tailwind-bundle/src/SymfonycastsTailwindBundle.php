<?php

/*
 * This file is part of the SymfonyCasts TailwindBundle package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonycasts\TailwindBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SymfonycastsTailwindBundle extends AbstractBundle
{
    private const PLATFORMS = ['auto', 'linux-arm64', 'linux-arm64-musl', 'linux-x64', 'linux-x64-musl', 'macos-arm64', 'macos-x64', 'windows-x64'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('input_css')
                    ->prototype('scalar')->end()
                    ->beforeNormalization()->castToArray()->end()
                    ->info('Paths to CSS files to process through Tailwind')
                    ->defaultValue(['%kernel.project_dir%/assets/styles/app.css'])
                ->end()
                ->scalarNode('config_file')
                    ->info('Path to the tailwind.config.js file')
                    ->defaultValue('%kernel.project_dir%/tailwind.config.js')
                ->end()
                ->scalarNode('binary')
                    ->info('The tailwind binary to use instead of downloading a new one')
                    ->defaultNull()
                ->end()
                ->scalarNode('binary_version')
                    ->info('Tailwind CLI version to download - null means the latest version')
                    ->defaultNull()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static function (string $version): string {
                            return 'v'.ltrim($version, 'vV');
                        })
                    ->end()
                ->end()
                ->enumNode('binary_platform')
                    ->values(self::PLATFORMS)
                    ->info('Tailwind CLI platform to download - "auto" will try to detect the platform automatically')
                    ->defaultValue('auto')
                ->end()
                ->scalarNode('postcss_config_file')
                    ->info('Path to PostCSS config file which is passed to the Tailwind CLI')
                    ->defaultNull()
                ->end()
                ->booleanNode('strict_mode')
                    ->info('When enabled, an exception will be thrown if there are no built assets (default: false in `test` env, true otherwise)')
                    ->defaultNull()
                ->end()
                ->integerNode('process_timeout')
                    ->info('Timeout in seconds for the Tailwind build process - use "0" to disable')
                    ->defaultValue(60)
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $strictMode = $config['strict_mode'] ?? ('test' !== $builder->getParameter('kernel.environment'));

        $builder->findDefinition('.tailwind.css_asset_compiler')
            ->replaceArgument(1, $strictMode)
        ;

        $builder->findDefinition('.tailwind.builder')
            ->replaceArgument(1, $config['input_css'])
            ->replaceArgument(3, $config['binary'])
            ->replaceArgument(4, $config['binary_version'])
            ->replaceArgument(5, $config['config_file'])
            ->replaceArgument(6, $config['postcss_config_file'])
            ->replaceArgument(7, $config['binary_platform'])
            ->replaceArgument(8, $config['process_timeout'])
        ;

        $builder->findDefinition('.tailwind.command.init')
            ->replaceArgument(1, $config['input_css'])
        ;

        $builder->findDefinition('.tailwind.command.update')
            ->replaceArgument(2, $config['binary'])
            ->replaceArgument(3, $config['binary_version'])
        ;
    }
}
