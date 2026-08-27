<?php

namespace App\Core\Security\DependencyInjection\Compiler;

use App\Core\Resource\DependencyInjection\Compiler\ResourceRegistrationPass;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * CapabilityRegistry'yi container derleme zamanında doldurur.
 *
 * Kaynaklar:
 *   1) cp-core/config/capabilities.yaml — çekirdek yetenekleri.
 *   2) Her bundle'ın (modülün) kendi Resources/config/capabilities.yaml'ı.
 *   3) #[CpResource] attribute'u taşıyan entity'lerin otomatik ürettiği
 *      yetenekler (Manifesto Law 4.2) — ResourceRegistrationPass zaten
 *      AYNI derleme geçişinde çalışıp bunları
 *      ResourceRegistrationPass::CONTAINER_PARAMETER altına yazmış olur;
 *      bu pass'in ondan SONRA çalışması gerekir (bkz. Kernel::build()'de
 *      eklenme sırası).
 *
 * Modüller Modules\ namespace'i altında olduğu için (Kernel.php'deki
 * boot()/build() izolasyonuyla aynı felsefe), bozuk bir capabilities.yaml
 * sadece o modülün yeteneklerinin kayıt olmamasına yol açar — container
 * derlemesi veya diğer modüller etkilenmez.
 */
final class CapabilityRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('App\Core\Security\CapabilityRegistry')) {
            return;
        }

        $definition = $container->getDefinition('App\Core\Security\CapabilityRegistry');

        $coreFile = $container->getParameter('kernel.project_dir').'/cp-core/config/capabilities.yaml';
        $this->registerFromFile($definition, $coreFile, 'core', $container);

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleFile = rtrim((string) $bundleMeta['path'], '/').'/Resources/config/capabilities.yaml';

            try {
                $this->registerFromFile($definition, $moduleFile, $bundleClass, $container);
            } catch (Throwable) {
                // Modül izolasyonu: bir modülün capabilities.yaml'ı bozuksa
                // sadece o modülün yetenekleri kayıt olmaz, derleme durmaz.
            }
        }

        $this->registerFromResources($definition, $container);
    }

    /**
     * Manifesto Law 4.2: #[CpResource(capabilities: ['create', 'edit'])]
     * ile işaretlenmiş her entity için "name.capability" biçiminde
     * (ör. "vehicle.create") yetenekleri otomatik üretir. Geliştirici bu
     * yetenekleri capabilities.yaml'a elle eklemek ZORUNDA değildir.
     */
    private function registerFromResources(\Symfony\Component\DependencyInjection\Definition $definition, ContainerBuilder $container): void
    {
        if (!$container->hasParameter(ResourceRegistrationPass::CONTAINER_PARAMETER)) {
            return;
        }

        /** @var list<array{class: string, name: string, module: string, capabilities: list<string>, auditable: bool, multiTenant: bool, workflow: ?string}> $resources */
        $resources = $container->getParameter(ResourceRegistrationPass::CONTAINER_PARAMETER);

        foreach ($resources as $resource) {
            if ($resource['name'] === '' || $resource['capabilities'] === []) {
                continue;
            }

            $expanded = array_values(array_map(
                static fn (string $capability): string => sprintf('%s.%s', $resource['name'], $capability),
                $resource['capabilities'],
            ));

            $definition->addMethodCall('registerMany', [$expanded, $resource['module']]);
        }
    }

    private function registerFromFile(
        \Symfony\Component\DependencyInjection\Definition $definition,
        string $file,
        string $source,
        ContainerBuilder $container,
    ): void {
        if (!is_file($file)) {
            return;
        }

        $container->addResource(new FileResource($file));

        $data = Yaml::parseFile($file);
        $capabilities = $data['capabilities'] ?? [];

        if (!is_array($capabilities) || $capabilities === []) {
            return;
        }

        $definition->addMethodCall('registerMany', [array_values($capabilities), $source]);
    }
}
