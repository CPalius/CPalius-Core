<?php

namespace App;

use App\Core\Api\DependencyInjection\Compiler\ApiRegistrationPass;
use App\Core\Cron\DependencyInjection\Compiler\CronCommandRegistrationPass;
use App\Core\Cron\DependencyInjection\Compiler\CronRegistrationPass;
use App\Core\DependencyInjection\Compiler\TailwindVarDirPass;
use App\Core\Hook\DependencyInjection\Compiler\HookRegistrationPass;
use App\Core\Menu\DependencyInjection\Compiler\AdminMenuRegistrationPass;
use App\Core\Module\ModuleRegistry;
use App\Core\Resource\DependencyInjection\Compiler\ResourceRegistrationPass;
use App\Core\Security\DependencyInjection\Compiler\CapabilityRegistrationPass;
use App\Core\Settings\DependencyInjection\Compiler\SettingsRegistrationPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Throwable;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * PSR-4 kökü olan Modules\ namespace'i sadece cp-content/modules/
     * altındaki modüllere ait. Bu prefix'i taşıyan bir bundle'ın boot()
     * hatası "modül hatası" sayılır ve izole edilir; FrameworkBundle gibi
     * çekirdek bundle'ların hataları asla yutulmaz.
     */
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function getProjectDir(): string
    {
        // cp-core/src/Kernel.php -> cp-core/src -> cp-core -> proje kökü
        return \dirname(__DIR__, 2);
    }

    /**
     * Manifesto Law 2.1 (Dynamic & Safe Booting): MicroKernelTrait'in
     * varsayılan registerBundles() implementasyonu SADECE config/bundles.php
     * dosyasını okur — Modules\ namespace'i altındaki modül bundle'ları
     * o dosyaya asla yazılmaz (bkz. bundles.php üstündeki not). Bunun
     * yerine ModuleRegistry::getHealthyModuleBundles() ile config/
     * active_modules.php dosyasını okuyup, karantina/doğrulama
     * kurallarından geçen modül bundle'larını buraya ekliyoruz.
     *
     * ModuleRegistry burada bir SERVİS olarak değil, doğrudan `new` ile
     * kullanılır: bu metot container henüz inşa edilmeden (hatta
     * inşa sürecinin bir parçası olarak) çağrılır, bu yüzden DI container'a
     * bağımlı olamaz — tıpkı bundles.php'nin kendisi gibi saf dosya sistemi
     * okumasıdır.
     */
    public function registerBundles(): iterable
    {
        $bundlesPath = $this->getConfigDir().'/bundles.php';

        if (is_file($bundlesPath)) {
            $contents = require $bundlesPath;
            foreach ($contents as $class => $envs) {
                if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                    yield new $class();
                }
            }
        }

        $moduleRegistry = new ModuleRegistry(
            activeModulesFile: $this->getConfigDir().'/active_modules.php',
            quarantineLogFile: $this->getProjectDir().'/cp-core/var/log/module_quarantine.log',
            modulesDir: $this->getProjectDir().'/cp-content/modules',
        );

        foreach ($moduleRegistry->getHealthyModuleBundles() as $moduleClass) {
            yield new $moduleClass();
        }
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir().'/cp-core/config';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/cp-core/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/cp-core/var/log';
    }

    /**
     * Üst sınıfın davranışı: herhangi bir bundle'ın boot() metodu hata
     * fırlatırsa tüm uygulama çöker. Burada, sadece bir "Modül"e ait
     * (Modules\ namespace'i altındaki) bundle'ların boot() hatalarını
     * izole ediyoruz — Core bundle'lar (FrameworkBundle vb.) hâlâ normal
     * davranır ve hataları olduğu gibi yükselir.
     */
    public function boot(): void
    {
        if ($this->booted) {
            // Zaten boot edilmiş: üst sınıfın kısa devre (services_resetter
            // vb.) mantığını olduğu gibi kullan.
            parent::boot();

            return;
        }

        if (!$this->container) {
            // BaseKernel::preBoot() private olduğu için doğrudan
            // çağrılamıyor; aynı iki adımı (initializeBundles +
            // initializeContainer) kendimiz tetikliyoruz. Bu adımlarda
            // hata olursa (Core seviyesinde) hata olduğu gibi yükselir.
            $this->initializeBundles();
            $this->initializeContainer();
        }

        foreach ($this->getBundles() as $bundle) {
            $bundle->setContainer($this->container);

            if (!str_starts_with($bundle::class, self::MODULE_NAMESPACE_PREFIX)) {
                $bundle->boot();
                continue;
            }

            try {
                $bundle->boot();
            } catch (Throwable $e) {
                $this->quarantineModuleAtRuntime($bundle::class, $e);
            }
        }

        $this->booted = true;
    }

    /**
     * Her bundle'ın (Core dahil) kendi Resources/config/services.yaml
     * dosyasını (varsa) container'a yükler. Modül bundle'ları için bu
     * adım izole edilir: bir modülün services.yaml'ı bozuksa (syntax
     * hatası, geçersiz servis tanımı) sadece o modülün servisleri devre
     * dışı kalır, container derlemesi ve diğer modüller etkilenmez.
     *
     * Not: Controller'ların autowire edilebilmesi (ör. TranslatorInterface
     * enjeksiyonu) için modülün kendi controller/servis sınıflarının bir
     * yerde "services" olarak tanımlı olması gerekir; wildcard resource
     * taraması modül geliştiricisinin kendi services.yaml'ında yapılır.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TailwindVarDirPass());

        // Sıralama kritik: ResourceRegistrationPass önce çalışıp
        // #[CpResource] taramasını ResourceRegistrationPass::CONTAINER_PARAMETER
        // altına yazmalı; CapabilityRegistrationPass bu parametreyi okuyarak
        // otomatik yetenekleri üretir (Manifesto Law 4.2). Symfony aynı
        // önceliğe sahip pass'leri ekleme sırasına göre çalıştırır, ama
        // burada niyeti açık kılmak için farklı öncelik veriyoruz.
        $container->addCompilerPass(new ResourceRegistrationPass(), priority: 10);
        $container->addCompilerPass(new CapabilityRegistrationPass(), priority: 0);

        // Menü ve ayar taramaları diğer pass'lerden bağımsızdır (aralarında
        // bir veri akışı yok), bu yüzden aynı öncelik tercih edilir.
        $container->addCompilerPass(new AdminMenuRegistrationPass(), priority: 10);
        $container->addCompilerPass(new SettingsRegistrationPass(), priority: 10);
        $container->addCompilerPass(new CronCommandRegistrationPass(), priority: 10);

        // HookRegistrationPass: ServiceLocatorTagPass::register() ile bir
        // "service_locator.xxx" servisi üretir; bu servisin de HookManager
        // argümanına referans olarak bağlanabilmesi için normal (autowire)
        // derleme geçişlerinden SONRA, ama container "removing" (private
        // servisleri temizleme) aşamasından ÖNCE çalışmalıdır. Varsayılan
        // öncelik (priority: 0) bu sırayı sağlar; diğer pass'lerle veri
        // bağımlılığı olmadığından erken çalışmasına gerek yoktur.
        $container->addCompilerPass(new HookRegistrationPass());
        $container->addCompilerPass(new ApiRegistrationPass());
        $container->addCompilerPass(new CronRegistrationPass());

        foreach ($this->getBundles() as $bundle) {
            $servicesFile = $bundle->getPath().'/Resources/config/services.yaml';

            if (!is_file($servicesFile)) {
                continue;
            }

            if (!str_starts_with($bundle::class, self::MODULE_NAMESPACE_PREFIX)) {
                // Core bundle'ların services.yaml'ı hata verirse bu gerçek
                // bir çekirdek hatasıdır, izole edilmeden yükselmelidir.
                $this->loadBundleServices($container, $servicesFile);
                continue;
            }

            try {
                $this->loadBundleServices($container, $servicesFile);
            } catch (Throwable $e) {
                $this->quarantineModuleAtRuntime($bundle::class, $e);
            }
        }
    }

    private function loadBundleServices(ContainerBuilder $container, string $servicesFile): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname($servicesFile)));
        $loader->load(\basename($servicesFile));
    }

    private function quarantineModuleAtRuntime(string $bundleClass, Throwable $e): void
    {
        $logFile = $this->getProjectDir().'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s modülü boot() aşamasında hata verdiği için çalışma anında atlandı. Sebep: %s',
            date('Y-m-d H:i:s'),
            $bundleClass,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
