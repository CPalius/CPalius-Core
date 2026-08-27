<?php

declare(strict_types=1);

namespace App\Core\Cron\DependencyInjection\Compiler;

use App\Core\Cron\Attribute\CpCronJob;
use App\Core\Cron\CronManager;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * Attribute Kulvarı'nın (#[CpCronJob]) VE Flat-File Kulvarı'nın
 * (cp-content/modules/*\/Hooks/cron.{job_name}.php) derleme zamanı ortak
 * toplayıcısı. HookRegistrationPass/ApiRegistrationPass ile AYNI iskelet:
 * dosya sistemi taraması + Reflection, modül izolasyonu try/catch(Throwable)
 * ile sağlanır.
 *
 * Flat-file cron dosyaları BİLİNÇLİ olarak "cron." önekiyle sınırlıdır (ör.
 * "cron.blog_publish.php") — aynı Hooks/ dizini hem #[CpHook] servislerini
 * hem #[CpCronJob] servislerini hem de bu flat-file görevleri barındırabilir
 * (Faz 7'nin "aynı dizin, farklı kulvar" konvansiyonu); önek olmadan her
 * .php dosyasını bir cron görevi sanmak, HookManager'ın hook noktası
 * dosyalarıyla çakışmaya yol açardı.
 *
 * Taranan konumlar: cp-core/src (çekirdek servisler, attribute kulvarı) +
 * her modülün kendi kök dizini (attribute kulvarı) + her modülün Hooks/
 * dizini (flat-file kulvarı, sadece "cron.*.php" kalıbı).
 */
final class CronRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';
    private const FLAT_FILE_PREFIX = 'cron.';

    public const CONTAINER_PARAMETER = 'cpalius.cron_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{jobName: string, schedule: string, description: string, sourceType: 'attribute'|'flat-file', serviceId: ?string, method: ?string, file: ?string}> $collected */
        $collected = [];
        $serviceIds = [];

        $coreServiceDir = $projectDir.'/cp-core/src';
        foreach ($this->scanAttributeDirectory($coreServiceDir, 'App\\', $container) as $item) {
            $collected[] = $item;
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
                foreach ($this->scanAttributeDirectory($moduleDir, $moduleNamespace, $container) as $item) {
                    $collected[] = $item;
                    $serviceIds[$item['serviceId']] = true;
                }
            } catch (Throwable) {
                // Modül izolasyonu: bir modülün dizini taranırken hata
                // oluşursa sadece o modülün attribute cron görevleri kayıt olmaz.
            }

            try {
                foreach ($this->scanFlatFileDirectory($moduleDir.'/Hooks', $container) as $item) {
                    $collected[] = $item;
                }
            } catch (Throwable) {
                // Modül izolasyonu: bkz. yukarıdaki catch bloğu.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition(CronManager::class)) {
            $locatorReferences = [];
            foreach (array_keys($serviceIds) as $serviceId) {
                if ($container->has($serviceId)) {
                    $locatorReferences[$serviceId] = new Reference($serviceId);
                }
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $locatorReferences);

            $definition = $container->getDefinition(CronManager::class);
            $definition->setArgument('$serviceLocator', $locatorReference);
        }
    }

    /**
     * @return list<array{jobName: string, schedule: string, description: string, sourceType: 'attribute', serviceId: string, method: string, file: null}>
     */
    private function scanAttributeDirectory(string $dir, string $namespacePrefix, ContainerBuilder $container): array
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

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                foreach ($reflection->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }

                    $cronAttributes = $method->getAttributes(CpCronJob::class);
                    if ($cronAttributes === []) {
                        continue;
                    }

                    if (!$container->has($className)) {
                        continue;
                    }

                    /** @var CpCronJob $cronJob */
                    $cronJob = $cronAttributes[0]->newInstance();

                    $collected[] = [
                        'jobName' => $cronJob->name,
                        'schedule' => $cronJob->schedule,
                        'description' => $cronJob->description,
                        'sourceType' => 'attribute',
                        'serviceId' => $className,
                        'method' => $method->getName(),
                        'file' => null,
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $collected;
    }

    /**
     * Flat-file cron görevleri servis/DI ihtiyacı duymadığı için burada
     * Reflection/class_exists yoktur — dosya adı doğrudan sözleşmedir:
     * "cron.{job_name}.{schedule_slug}.php" değil, sade "cron.{job_name}.php"
     * (zamanlama dosyanın İÇİNDE, ilk satırdaki `# schedule: ...` yorumuyla
     * DEĞİL, dosyanın döndürdüğü dizi üzerinden okunur — bkz. HookManager'ın
     * includeIsolated() ile aynı izolasyon prensibi).
     *
     * @return list<array{jobName: string, schedule: string, description: string, sourceType: 'flat-file', serviceId: null, method: null, file: string}>
     */
    private function scanFlatFileDirectory(string $hooksDir, ContainerBuilder $container): array
    {
        if (!is_dir($hooksDir)) {
            return [];
        }

        $container->addResource(new DirectoryResource($hooksDir, '/\.php$/'));

        $collected = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($hooksDir, \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $filename = (string) $file->getFilename();

            if (!str_starts_with($filename, self::FLAT_FILE_PREFIX)) {
                continue;
            }

            $jobName = substr($filename, strlen(self::FLAT_FILE_PREFIX), -4);
            if ($jobName === '') {
                continue;
            }

            try {
                $definition = include $file->getPathname();
            } catch (Throwable) {
                continue;
            }

            if (!is_array($definition) || !isset($definition['schedule']) || !is_string($definition['schedule'])) {
                // Flat-file cron sözleşmesi: dosya, en az 'schedule' anahtarı
                // olan bir dizi DÖNMELİDİR (bkz. örnek dosya). Sözleşmeye
                // uymayan bir dosya sessizce atlanır (Core Never Dies).
                continue;
            }

            $collected[] = [
                'jobName' => $jobName,
                'schedule' => $definition['schedule'],
                'description' => is_string($definition['description'] ?? null) ? $definition['description'] : '',
                'sourceType' => 'flat-file',
                'serviceId' => null,
                'method' => null,
                'file' => $file->getPathname(),
            ];
        }

        return $collected;
    }
}
