<?php

declare(strict_types=1);

namespace App\Core\Hook\DependencyInjection\Compiler;

use App\Core\Hook\Attribute\CpHook;
use App\Core\Hook\HookManager;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * Attribute Kulvarı'nın (Symfony tarzı) derleme zamanı toplayıcısı.
 *
 * AdminMenuRegistrationPass/SettingsRegistrationPass ile AYNI iskelet:
 * dosya sistemi taraması + Reflection, modül izolasyonu try/catch(Throwable)
 * ile sağlanır. Tek fark: burada sadece bir "tanım listesi" üretmekle
 * kalınmaz, ayrıca #[CpHook] taşıyan HER servis HookManager'ın lazy
 * ServiceLocator'ına da eklenir — HookManager çalışma zamanında bu
 * servisleri isimleriyle (FQCN) çözer, tüm dinleyicileri eager olarak
 * inşa etmez (Manifesto Law 6.1 ruhu: hiç tetiklenmeyen bir hook'un
 * dinleyici servisi hiçbir zaman instantiate edilmez).
 *
 * Taranan konumlar: cp-core/src (çekirdek servisler) + her modülün kendi
 * kök dizini (modül servisleri her yerde olabilir — Controller, Service,
 * Hooks... bu yüzden AdminMenuRegistrationPass'in aksine tek bir alt
 * dizine değil, tüm App\:/Modules\: servis tanımlarının üzerinden geçilir).
 */
final class HookRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public const CONTAINER_PARAMETER = 'cpalius.hook_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{hookPoint: string, priority: int, serviceId: string, method: string}> $collected */
        $collected = [];
        $serviceIds = [];

        $coreServiceDir = $projectDir.'/cp-core/src';
        foreach ($this->scanDirectory($coreServiceDir, 'App\\', $container) as $item) {
            $collected[] = ['hookPoint' => $item['hookPoint'], 'priority' => $item['priority'], 'serviceId' => $item['serviceId'], 'method' => $item['method']];
            $serviceIds[$item['serviceId']] = true;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleDir = rtrim((string) $bundleMeta['path'], '/');
            $moduleNamespace = $bundleMeta['namespace'].'\\';

            try {
                foreach ($this->scanDirectory($moduleDir, $moduleNamespace, $container) as $item) {
                    $collected[] = ['hookPoint' => $item['hookPoint'], 'priority' => $item['priority'], 'serviceId' => $item['serviceId'], 'method' => $item['method']];
                    $serviceIds[$item['serviceId']] = true;
                }
            } catch (Throwable) {
                // Modül izolasyonu: bir modülün dizini taranırken hata
                // oluşursa sadece o modülün attribute hook'ları kayıt olmaz.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition(HookManager::class)) {
            $locatorReferences = [];
            foreach (array_keys($serviceIds) as $serviceId) {
                if ($container->has($serviceId)) {
                    $locatorReferences[$serviceId] = new Reference($serviceId);
                }
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $locatorReferences);

            $definition = $container->getDefinition(HookManager::class);
            $definition->setArgument('$serviceLocator', $locatorReference);
        }
    }

    /**
     * @return list<array{hookPoint: string, priority: int, serviceId: string, method: string}>
     */
    private function scanDirectory(string $dir, string $namespacePrefix, ContainerBuilder $container): array
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
                if (!class_exists($className) && !interface_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                foreach ($reflection->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }

                    $hookAttributes = $method->getAttributes(CpHook::class);
                    if ($hookAttributes === []) {
                        continue;
                    }

                    if (!$container->has($className)) {
                        continue;
                    }

                    foreach ($hookAttributes as $hookAttribute) {
                        /** @var CpHook $hook */
                        $hook = $hookAttribute->newInstance();

                        $collected[] = [
                            'hookPoint' => $hook->hookPoint,
                            'priority' => $hook->priority,
                            'serviceId' => $className,
                            'method' => $method->getName(),
                        ];
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $collected;
    }
}
