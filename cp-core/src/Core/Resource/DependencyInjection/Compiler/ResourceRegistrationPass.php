<?php

declare(strict_types=1);

namespace App\Core\Resource\DependencyInjection\Compiler;

use App\Core\Annotation\Auditable;
use App\Core\Annotation\CpResource;
use App\Core\Annotation\Publishable;
use App\Core\Annotation\SoftDeletable;
use App\Core\Resource\ResourceDefinition;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * ResourceRegistry'yi container derleme zamanında doldurur.
 *
 * #[CpResource] attribute'u taşıyan sınıfları bulmak için Doctrine'in
 * kendi metadata sürücüsünü değil, doğrudan dosya sistemi taramasını +
 * PHP Reflection'ı kullanır: bu sayede ResourceRegistry, Doctrine ORM'in
 * ManagerRegistry'sinden (runtime bağımlılığı) tamamen bağımsız, saf bir
 * derleme-zamanı çıktısı olarak kalır.
 *
 * Taranan konumlar:
 *   1) cp-core/src/Entity — çekirdek entity'ler ("core" modülü).
 *   2) Her modülün src/Entity dizini (varsa) — modül izolasyonu ile:
 *      bir modülün entity dosyası reflection sırasında hata verirse
 *      (syntax hatası, eksik bağımlılık vb.) sadece o modülün kaynakları
 *      kayıt olmaz, derleme durmaz (bkz. Kernel::build() ile aynı felsefe).
 */
final class ResourceRegistrationPass implements CompilerPassInterface
{
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    /**
     * CapabilityRegistrationPass bu sabit servis ID'si üzerinden, bu pass
     * tarafından derleme zamanında toplanan #[CpResource] tanımlarını
     * (henüz ResourceRegistry'ye method-call olarak eklenmiş, ama runtime'da
     * çözülmemiş ham veriyi) okuyup kendi capability listesini üretir.
     * İki pass arasındaki tek bağ budur; birbirlerinin sınıflarına doğrudan
     * bağımlı değildirler.
     */
    public const CONTAINER_PARAMETER = 'cpalius.resource_definitions';

    public function process(ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        /** @var list<array{class: string, name: string, module: string, capabilities: list<string>, auditable: bool, multiTenant: bool, workflow: ?string, publishable: bool, softDeletable: bool}> $collected */
        $collected = [];

        $coreEntityDir = $projectDir.'/cp-core/src/Entity';
        foreach ($this->scanDirectory($coreEntityDir, 'App\\Entity\\', $container) as $resource) {
            $collected[] = $resource;
        }

        foreach ($container->getParameter('kernel.bundles_metadata') as $bundleName => $bundleMeta) {
            $bundleClass = $bundleMeta['namespace'].'\\'.$bundleName;
            if (!str_starts_with($bundleClass, self::MODULE_NAMESPACE_PREFIX)) {
                continue;
            }

            $moduleEntityDir = rtrim((string) $bundleMeta['path'], '/').'/src/Entity';
            $moduleEntityNamespace = $bundleMeta['namespace'].'\\Entity\\';

            try {
                foreach ($this->scanDirectory($moduleEntityDir, $moduleEntityNamespace, $container) as $resource) {
                    $collected[] = $resource;
                }
            } catch (Throwable) {
                // Modül izolasyonu: bir modülün entity dizini taranırken
                // hata oluşursa sadece o modülün kaynakları kayıt olmaz.
            }
        }

        $container->setParameter(self::CONTAINER_PARAMETER, $collected);

        if ($container->hasDefinition('App\Core\Resource\ResourceRegistry')) {
            $definition = $container->getDefinition('App\Core\Resource\ResourceRegistry');

            foreach ($collected as $resource) {
                $definition->addMethodCall('add', [new \Symfony\Component\DependencyInjection\Definition(ResourceDefinition::class, [
                    $resource['class'],
                    $resource['name'],
                    $resource['module'],
                    $resource['capabilities'],
                    $resource['auditable'],
                    $resource['multiTenant'],
                    $resource['workflow'],
                    $resource['publishable'],
                    $resource['softDeletable'],
                ])]);
            }
        }
    }

    /**
     * @return list<array{class: string, name: string, module: string, capabilities: list<string>, auditable: bool, multiTenant: bool, workflow: ?string, publishable: bool, softDeletable: bool}>
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
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                $cpResourceAttributes = $reflection->getAttributes(CpResource::class);
                [$publishable, $softDeletable, $auditableBehavior] = $this->detectBehaviors($reflection);

                // #[CpResource] hiç yoksa, sadece bir davranış attribute'u
                // (#[Publishable]/#[SoftDeletable]/#[Auditable]) taşıyan
                // "platform kaynağı olmayan" bir entity söz konusu olabilir.
                // Bu sınıf yine de ResourceRegistry'ye kaydedilir — ama
                // capability/module/workflow gibi #[CpResource]'a özgü
                // alanlar boş/varsayılan kalır (hiç yetenek üretilmez).
                if ($cpResourceAttributes === [] && !$publishable && !$softDeletable && !$auditableBehavior) {
                    continue;
                }

                if ($cpResourceAttributes !== []) {
                    /** @var CpResource $resource */
                    $resource = $cpResourceAttributes[0]->newInstance();

                    $collected[] = [
                        'class' => $className,
                        'name' => $resource->name,
                        'module' => $resource->module,
                        'capabilities' => $resource->capabilities,
                        'auditable' => $resource->auditable || $auditableBehavior,
                        'multiTenant' => $resource->multiTenant,
                        'workflow' => $resource->workflow,
                        'publishable' => $publishable,
                        'softDeletable' => $softDeletable,
                    ];
                } else {
                    $collected[] = [
                        'class' => $className,
                        'name' => '',
                        'module' => 'core',
                        'capabilities' => [],
                        'auditable' => $auditableBehavior,
                        'multiTenant' => false,
                        'workflow' => null,
                        'publishable' => $publishable,
                        'softDeletable' => $softDeletable,
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
     * Bir entity sınıfının hangi kompozisyonel davranışları (#[Publishable],
     * #[SoftDeletable], #[Auditable]) taşıdığını tespit eder. Bu üç
     * attribute birbirinden BAĞIMSIZDIR — bir sınıf ikisini birden, birini
     * veya hiçbirini taşıyabilir; #[CpResource] ile zorunlu bir ilişkisi
     * yoktur.
     *
     * @return array{0: bool, 1: bool, 2: bool} [publishable, softDeletable, auditable]
     */
    private function detectBehaviors(\ReflectionClass $reflection): array
    {
        return [
            $reflection->getAttributes(Publishable::class) !== [],
            $reflection->getAttributes(SoftDeletable::class) !== [],
            $reflection->getAttributes(Auditable::class) !== [],
        ];
    }
}
