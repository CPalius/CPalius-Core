<?php

namespace App;

use App\Core\Api\DependencyInjection\Compiler\ApiRegistrationPass;
use App\Core\Cron\DependencyInjection\Compiler\CronCommandRegistrationPass;
use App\Core\Cron\DependencyInjection\Compiler\CronRegistrationPass;
use App\Core\Display\DependencyInjection\Compiler\ViewModeRegistrationPass;
use App\Core\Entity\DependencyInjection\Compiler\EntityTypeRegistrationPass;
use App\Core\DependencyInjection\Compiler\TailwindVarDirPass;
use App\Core\Field\DependencyInjection\Compiler\FieldTypeRegistrationPass;
use App\Core\Hook\DependencyInjection\Compiler\HookRegistrationPass;
use App\Core\Localization\DependencyInjection\Compiler\LocalesPatternPass;
use App\Core\Menu\DependencyInjection\Compiler\AdminMenuRegistrationPass;
use App\Core\Module\ModuleEntityMappingResolver;
use App\Core\Module\DependencyInjection\Compiler\ModuleContributionPass;
use App\Core\Module\DependencyInjection\Compiler\ModuleMigrationsPass;
use App\Core\Theme\DependencyInjection\Compiler\ThemeTwigPathPass;
use App\Core\Webhook\DependencyInjection\Compiler\InboundWebhookHandlerPass;
use App\Core\Module\ModuleRegistry;
use App\Core\Resource\DependencyInjection\Compiler\ResourceRegistrationPass;
use App\Core\Security\DependencyInjection\Compiler\CapabilityRegistrationPass;
use App\Core\Settings\DependencyInjection\Compiler\SettingsRegistrationPass;
use App\Core\Token\DependencyInjection\Compiler\TokenTypeRegistrationPass;
use App\Core\TextFormat\DependencyInjection\Compiler\TextFormatRegistrationPass;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
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
     * The Modules\ PSR-4 root covers only cp-content/modules/ bundles.
     * Boot failures there are quarantined; core bundle errors are never swallowed.
     */
    private const MODULE_NAMESPACE_PREFIX = 'Modules\\';

    public function getProjectDir(): string
    {
        // cp-core/src/Kernel.php -> cp-core/src -> cp-core -> project root
        return \dirname(__DIR__, 2);
    }

    /**
     * Manifesto Law 2.1: core bundles come from bundles.php; healthy module
     * bundles are added from active_modules.php via ModuleRegistry.
     * ModuleRegistry is instantiated directly — the container is not built yet.
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
     * Parent boot() fails the whole app on any bundle error. Only Modules\
     * bundle boot failures are quarantined; core bundles still propagate errors.
     */
    public function boot(): void
    {
        if ($this->booted) {
            // Already booted: use parent short-circuit (services_resetter, etc.).
            parent::boot();

            return;
        }

        if (!$this->container) {
            // BaseKernel::preBoot() is private; run initializeBundles +
            // initializeContainer ourselves. Core errors still propagate.
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
     * Loads each bundle's Resources/config/services.yaml when present.
     * Broken module YAML is quarantined so other modules and compilation continue.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->registerActiveModuleDoctrineMappings($container);

        $container->addCompilerPass(new TailwindVarDirPass());

        // Phase 3: %cpalius.locales_pattern% must exist before routes reference it.
        $container->addCompilerPass(new LocalesPatternPass(), priority: 100);

        // Phase 6: theme Twig namespaces and module migration directories are both
        // compile-time concerns, resolved before the registration passes below.
        $container->addCompilerPass(new ThemeTwigPathPass(), priority: 90);
        $container->addCompilerPass(new ModuleMigrationsPass(), priority: 90);
        $container->addCompilerPass(new ModuleContributionPass(), priority: 90);

        // ResourceRegistrationPass must run before CapabilityRegistrationPass (Law 4.2).
        $container->addCompilerPass(new ResourceRegistrationPass(), priority: 10);
        $container->addCompilerPass(new CapabilityRegistrationPass(), priority: 0);

        // Menu and settings passes are independent; same priority is fine.
        $container->addCompilerPass(new AdminMenuRegistrationPass(), priority: 10);
        $container->addCompilerPass(new SettingsRegistrationPass(), priority: 10);
        $container->addCompilerPass(new CronCommandRegistrationPass(), priority: 10);
        $container->addCompilerPass(new ViewModeRegistrationPass(), priority: 10);
        $container->addCompilerPass(new TokenTypeRegistrationPass(), priority: 10);
        $container->addCompilerPass(new TextFormatRegistrationPass(), priority: 10);

        // HookRegistrationPass needs default priority: after autowire, before removing.
        $container->addCompilerPass(new HookRegistrationPass());
        $container->addCompilerPass(new ApiRegistrationPass());
        $container->addCompilerPass(new CronRegistrationPass());

        // Collects #[CpEntityType] classes (core + modules) into EntityTypeRegistry.
        // Runs before the field pass so the field layer can trust the type list.
        $container->addCompilerPass(new EntityTypeRegistrationPass());

        // Collects #[CpFieldType] classes (core + modules) into FieldTypeRegistry.
        $container->addCompilerPass(new FieldTypeRegistrationPass());

        // Maps registered inbound webhook endpoint ids to their module handler
        // services for InboundWebhookJobHandler. Runs after autowiring.
        $container->addCompilerPass(new InboundWebhookHandlerPass());

        foreach ($this->getBundles() as $bundle) {
            $servicesFile = $bundle->getPath().'/Resources/config/services.yaml';

            if (!is_file($servicesFile)) {
                continue;
            }

            if (!str_starts_with($bundle::class, self::MODULE_NAMESPACE_PREFIX)) {
                // Core services.yaml errors are real core failures — do not quarantine.
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

    /**
     * Registers Doctrine attribute mappings for healthy modules with an Entity/ dir.
     * Disabled modules get neither mapping nor services.
     */
    private function registerActiveModuleDoctrineMappings(ContainerBuilder $container): void
    {
        $moduleRegistry = new ModuleRegistry(
            activeModulesFile: $this->getConfigDir().'/active_modules.php',
            quarantineLogFile: $this->getProjectDir().'/cp-core/var/log/module_quarantine.log',
            modulesDir: $this->getProjectDir().'/cp-content/modules',
        );

        foreach (ModuleEntityMappingResolver::resolveMany($moduleRegistry->getHealthyModuleBundles()) as $mapping) {
            $container->addCompilerPass(
                DoctrineOrmMappingsPass::createAttributeMappingDriver(
                    [$mapping['namespace']],
                    [$mapping['dir']],
                    [],
                    false,
                    [$mapping['alias'] => $mapping['namespace']],
                ),
            );
        }
    }

    private function quarantineModuleAtRuntime(string $bundleClass, Throwable $e): void
    {
        $logFile = $this->getProjectDir().'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s module skipped at runtime because boot() failed. Reason: %s',
            date('Y-m-d H:i:s'),
            $bundleClass,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
