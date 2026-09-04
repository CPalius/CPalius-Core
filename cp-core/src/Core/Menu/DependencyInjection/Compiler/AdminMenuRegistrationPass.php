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
 * AdminMenuRegistry'yi container derleme zamanında doldurur.
 *
 * ResourceRegistrationPass ile BİREBİR AYNI iskelet — tek fark taranan
 * hedef (Entity yerine Controller sınıfları/metotları) ve okunan attribute
 * (#[CpAdminMenu], TARGET_METHOD). Aynı modül izolasyonu felsefesi
 * geçerlidir: bir modülün Controller dizini taranırken hata oluşursa
 * (syntax hatası, eksik bağımlılık vb.) sadece o modülün menü öğeleri
 * kayıt olmaz, derleme durmaz.
 *
 * Menü öğelerinin modül aktiflik/yetki filtrelemesi burada YAPILMAZ:
 * bu pass derleme zamanında sabittir, ama active_modules.php çalışma
 * zamanında (cache temizlemeden) değişebilir. Bu yüzden BURADA aktif
 * olmayan modüllerin öğeleri de dahil TÜM öğeler toplanır; gerçek
 * filtreleme AdminMenuRuntime içinde her render'da taze yapılır.
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
                // Modül izolasyonu: bir modülün Controller dizini taranırken
                // hata oluşursa sadece o modülün menü öğeleri kayıt olmaz.
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
                        // Kalıtımla gelen metotlar (ör. AbstractController'dan)
                        // bu sınıfa ait değildir, atlanır.
                        continue;
                    }

                    $menuAttributes = $method->getAttributes(CpAdminMenu::class);
                    if ($menuAttributes === []) {
                        continue;
                    }

                    $methodRouteAttributes = $method->getAttributes(Route::class);
                    if ($methodRouteAttributes === []) {
                        // #[CpAdminMenu] taşıyan ama route'u olmayan bir metot
                        // sidebar'a bağlanamaz; sessizce atlanır.
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
                // Tek bir dosyanın reflection'ı başarısız olursa (namespace
                // uyuşmazlığı, eksik parent class vb.) o dosya atlanır;
                // tüm tarama iptal edilmez.
                continue;
            }
        }

        return $collected;
    }

    /**
     * Menü öğesi için aktif-sayfa eşleştirmesinde kullanılan prefix.
     * Sınıf düzeyindeki geniş prefix (ör. admin_forum_) yerine, her action
     * kendi alt rotalarını kapsayan dar bir prefix alır (ör. admin_forum_sections_).
     */
    private function resolveMenuRoutePrefix(string $classRoutePrefix, string $methodRouteName, string $routeName): string
    {
        if ($classRoutePrefix === '') {
            return $routeName;
        }

        if (!str_ends_with($classRoutePrefix, '_')) {
            return $routeName;
        }

        // admin_forum_permissions_, admin_blog_post_ gibi alt-controller prefix'leri
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
