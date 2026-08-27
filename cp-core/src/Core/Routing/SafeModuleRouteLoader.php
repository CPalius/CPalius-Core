<?php

namespace App\Core\Routing;

use App\Core\Module\ModuleRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

/**
 * cp-content/modules/*\/Resources/config/routes.yaml dosyalarını
 * tek tek, birbirinden izole şekilde yükler.
 *
 * Standart Symfony wildcard import'unun (`resource: '.../*\/routes.yaml'`)
 * aksine, burada TEK bir modülün route dosyası bozuk olsa bile (syntax
 * hatası, eksik controller, hatalı YAML) diğer modüllerin route'ları ve
 * Core'un kendi route'ları etkilenmez. Hatalı modül, henüz Bundle olarak
 * yüklenmediyse zaten burada denenmez (bkz. ModuleRegistry); yüklendiği
 * halde route dosyası bozuksa burada yakalanıp atlanır.
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
        } catch (Throwable $e) {
            $this->logger?->error('{module} modülünün dizini tespit edilemedi: {message}', [
                'module' => $moduleClass,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $routesFile = $moduleDir.'/Resources/config/routes.yaml';

        if (!is_file($routesFile)) {
            // Modülün route dosyası yoksa bu bir hata değil, sadece
            // o modülün route'u olmadığı anlamına gelir.
            return;
        }

        try {
            // Ana resolver kullanılıyor ki routes.yaml içindeki
            // "type: attribute" gibi iç içe import'lar da (controller
            // attribute'larını okuyan AttributeClassLoader) doğru
            // şekilde çözülebilsin.
            $subLoader = $this->resolve($routesFile, 'yaml');
            $moduleCollection = $subLoader->load($routesFile, 'yaml');
            $collection->addCollection($moduleCollection);
        } catch (Throwable $e) {
            $this->logger?->error('{module} modülünün route dosyası yüklenemedi, modül route\'ları atlandı: {message}', [
                'module' => $moduleClass,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
