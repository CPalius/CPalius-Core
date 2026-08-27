<?php

namespace App\Core\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Pristine Root: symfonycasts/tailwind-bundle, derlenmiş CSS önbelleğini
 * varsayılan olarak proje kökünde var/tailwind altına yazar (bundle'ın
 * kendi servis tanımında sabitlenmiştir, config ile değiştirilemez).
 * Bu pass, servis tanımı bundle'ın loadExtension() metodu tarafından
 * kurulduktan SONRA çalışıp 3. argümanı (tailwindVarDir) cp-core/var
 * altına yönlendirir; böylece tüm var/ içeriği tek bir kök altında kalır.
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
