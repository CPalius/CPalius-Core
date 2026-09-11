<?php

declare(strict_types=1);

namespace App\Core\Menu\DependencyInjection\Compiler;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Menu\MenuItemDefinition;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Populates AdminMenuRegistry at compile time (same skeleton as ResourceRegistrationPass; scans controllers for #[CpAdminMenu]).
 * Module isolation: one broken controller dir skips that module only. Active/capability filtering runs in AdminMenuRuntime at render time.
 */
final class AdminMenuRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public const CONTAINER_PARAMETER = 'cpalius.admin_menu_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{label: string, icon: string, panel: string, priority: int, capability: ?string, group: ?string, routeName: string, routePrefix: string, module: string, controllerClass: string, method: string, parent: ?string}> $collected */
        $collected = [];

        $coreControllerDir = $projectDir.'/cp-core/src/Controller';
        foreach ($this->scanDirectory($coreControllerDir, 'App\\Controller\\', 'core', $container) as $item) {
            $collected[] = $item;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleControllerDir = rtrim((string) $bundleMeta['path'], '/').'/Controller';
            $moduleControllerNamespace = $bundleMeta['namespace'].'\\Controller\\';

            try {
                foreach ($this->scanDirectory($moduleControllerDir, $moduleControllerNamespace, $bundleClass, $container) as $item) {
                    $collected[] = $item;
                }
            } catch (Throwable) {
                // Module isolation: scan failure skips that module's menu items only.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition('App\Core\Menu\AdminMenuRegistry')) {
            $definition = $container->getDefinition('App\Core\Menu\AdminMenuRegistry');

            foreach ($collected as $item) {
                $definition->addMethodCall('add', [new Definition(MenuItemDefinition::class, [
                    $item['label'],
                    $item['icon'],
                    $item['panel'],
                    $item['priority'],
                    $item['capability'],
                    $item['group'],
                    $item['routeName'],
                    $item['routePrefix'],
                    $item['module'],
                    $item['controllerClass'],
                    $item['method'],
                    $item['parent'],
                ])]);
            }
        }
    }

    /**
     * @return list<array{label: string, icon: string, panel: string, priority: int, capability: ?string, group: ?string, routeName: string, routePrefix: string, module: string, controllerClass: string, method: string, parent: ?string}>
     */
    private function scanDirectory(string $dir, string $namespacePrefix, string $module, ContainerBuilder $container): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $container->addResource(new DirectoryResource($dir, '/\.php$/'));

        $collected = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $relativePath = ltrim(substr((string) $file->getPathname(), strlen($dir)), '/\\');
            $className = $namespacePrefix.str_replace(['/', '\\'], '\\', substr($relativePath, 0, -4));

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
                        // Skip inherited methods (e.g. from AbstractController).
                        continue;
                    }

                    $menuAttributes = $method->getAttributes(CpAdminMenu::class);
                    if ($menuAttributes === []) {
                        continue;
                    }

                    $methodRouteAttributes = $method->getAttributes(Route::class);
                    if ($methodRouteAttributes === []) {
                        // #[CpAdminMenu] without #[Route] cannot link in sidebar; skip silently.
                        continue;
                    }

                    /** @var Route $methodRoute */
                    $methodRoute = $methodRouteAttributes[0]->newInstance();
                    $methodRouteName = (string) ($methodRoute->getName() ?? '');

                    $routeName = $classRoutePrefix.$methodRouteName;
                    if ($routeName === '') {
                        continue;
                    }

                    /** @var CpAdminMenu $menu */
                    $menu = $menuAttributes[0]->newInstance();

                    $collected[] = [
                        'label' => $menu->label,
                        'icon' => $menu->icon,
                        'panel' => $menu->panel,
                        'priority' => $menu->priority,
                        'capability' => $menu->capability,
                        'group' => $menu->group,
                        'routeName' => $routeName,
                        'routePrefix' => $this->resolveMenuRoutePrefix($classRoutePrefix, $methodRouteName, $routeName),
                        'module' => $module,
                        'controllerClass' => $className,
                        'method' => $method->getName(),
                        'parent' => $menu->parent,
                    ];
                }
            } catch (Throwable) {
                // Reflection failure on one file skips that file; scan continues.
                continue;
            }
        }

        return $collected;
    }

    /** Route prefix for active-page matching; narrow per-action prefix instead of broad class-level prefix. */
    private function resolveMenuRoutePrefix(string $classRoutePrefix, string $methodRouteName, string $routeName): string
    {
        if ($classRoutePrefix === '') {
            return $routeName;
        }

        if (!str_ends_with($classRoutePrefix, '_')) {
            return $routeName;
        }

        // Sub-controller prefixes like admin_forum_permissions_, admin_blog_post_
        $segments = array_values(array_filter(explode('_', rtrim($classRoutePrefix, '_'))));
        if (count($segments) >= 3) {
            return $classRoutePrefix;
        }

        if (!str_contains($methodRouteName, '_')) {
            return $routeName;
        }

        $segment = explode('_', $methodRouteName, 2)[0];

        return $classRoutePrefix.$segment.'_';
    }
}
