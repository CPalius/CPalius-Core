<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Module\ModuleRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads each module Resources/config/routes.yaml in isolation.
 * One broken module route file does not break others; failures are logged and skipped (see ModuleRegistry).
 */
final class SafeModuleRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \RuntimeException('Do not add the "cp_modules" loader twice.');
        }

        $collection = new RouteCollection();

        foreach ($this->moduleRegistry->getHealthyModuleBundles() as $moduleClass) {
            $this->loadModuleRoutes($collection, $moduleClass);
        }

        $this->loaded = true;

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'cp_modules';
    }

    private function loadModuleRoutes(RouteCollection $collection, string $moduleClass): void
    {
        try {
            $reflection = new \ReflectionClass($moduleClass);
            $moduleDir = \dirname($reflection->getFileName());
        } catch (\Throwable $e) {
            $this->logger?->error('{module} module directory could not be resolved: {message}', [
                'module' => $moduleClass,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $routesFile = $moduleDir.'/Resources/config/routes.yaml';

        if (!is_file($routesFile)) {
            // Missing routes.yaml is normal — the module simply has no routes.
            return;
        }

        try {
            // Main resolver so nested imports (e.g. type: attribute / AttributeClassLoader) resolve correctly.
            $subLoader = $this->resolve($routesFile, 'yaml');
            $moduleCollection = $subLoader->load($routesFile, 'yaml');
            $collection->addCollection($moduleCollection);
        } catch (\Throwable $e) {
            $this->logger?->error('{module} module route file could not be loaded; module routes skipped: {message}', [
                'module' => $moduleClass,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
