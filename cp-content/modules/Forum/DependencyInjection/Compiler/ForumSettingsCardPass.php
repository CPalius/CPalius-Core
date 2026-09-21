<?php

declare(strict_types=1);

namespace Modules\Forum\DependencyInjection\Compiler;

use Modules\Forum\Attribute\ForumSettingsCard;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Scans Controller/Admin for #[ForumSettingsCard] and stores the result as a
 * container parameter, the same skeleton Hosting uses for #[HostingSettingsCard].
 */
final class ForumSettingsCardPass implements CompilerPassInterface
{
    public const CONTAINER_PARAMETER = 'forum.settings_cards';

    public function process(ContainerBuilder $container): void
    {
        $dir = \dirname(__DIR__, 2).'/Controller/Admin';
        $namespace = 'Modules\\Forum\\Controller\\Admin\\';

        $collected = [];

        if (is_dir($dir)) {
            $container->addResource(new DirectoryResource($dir, '/\.php$/'));

            $files = new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
                '/\.php$/',
            );

            foreach ($files as $file) {
                $relativePath = ltrim(substr((string) $file->getPathname(), \strlen($dir)), '/\\');
                $className = $namespace.str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));

                try {
                    if (!class_exists($className)) {
                        continue;
                    }

                    $reflection = new \ReflectionClass($className);

                    $classRoutePrefix = '';
                    $classRouteAttributes = $reflection->getAttributes(Route::class);
                    if ($classRouteAttributes !== []) {
                        /** @var Route $classRoute */
                        $classRoute = $classRouteAttributes[0]->newInstance();
                        $classRoutePrefix = (string) ($classRoute->getName() ?? '');
                    }

                    foreach ($reflection->getMethods() as $method) {
                        if ($method->getDeclaringClass()->getName() !== $className) {
                            continue;
                        }

                        $cardAttributes = $method->getAttributes(ForumSettingsCard::class);
                        if ($cardAttributes === []) {
                            continue;
                        }

                        $methodRouteAttributes = $method->getAttributes(Route::class);
                        if ($methodRouteAttributes === []) {
                            continue;
                        }

                        /** @var Route $methodRoute */
                        $methodRoute = $methodRouteAttributes[0]->newInstance();
                        $routeName = $classRoutePrefix.(string) ($methodRoute->getName() ?? '');

                        if ($routeName === '') {
                            continue;
                        }

                        /** @var ForumSettingsCard $card */
                        $card = $cardAttributes[0]->newInstance();

                        $collected[] = [
                            'label' => $card->label,
                            'description' => $card->description,
                            'icon' => $card->icon,
                            'group' => $card->group,
                            'priority' => $card->priority,
                            'capability' => $card->capability ?? 'forum.section.manage',
                            'route' => $routeName,
                        ];
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        usort($collected, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);
    }
}
