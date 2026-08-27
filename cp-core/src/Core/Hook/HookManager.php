<?php

declare(strict_types=1);

namespace App\Core\Hook;

use App\Core\Module\ModuleRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Faz 7A: CPalius'un "Askeri Düzeyde İzole Hook Sistemi"nin ana orkestra
 * şefi. İki bağımsız kulvarı tek bir trigger() çağrısında birleştirir:
 *
 *   1) Flat-File Kulvarı (Cotonti tarzı): cp-content/modules/*\/Hooks/{hook_point}.php
 *      kalıbındaki dosyalar. Her dosya kapatılmış (bound), izole bir Closure
 *      scope'u içinde include edilir — dosyanın $this'i yoktur, dış scope'a
 *      erişemez, sadece kendisine enjekte edilen yerel $context değişkenini
 *      görür. Bu, üçüncü parti bir hook dosyasının yanlışlıkla (veya kasıtlı
 *      olarak) çağıran fonksiyonun scope'undaki değişkenleri okuyup/yazmasını
 *      İMKANSIZ kılar (değişken sızıntısı yasak).
 *
 *   2) Attribute Kulvarı (Symfony tarzı): #[CpHook('hook_point')] ile
 *      işaretlenmiş servis metotları, HookRegistrationPass tarafından
 *      derleme zamanında toplanır ve burada DI konteynerinden lazy olarak
 *      (ServiceLocator üzerinden) çekilip çağrılır.
 *
 * Core Never Dies Zırhı (Manifesto Law 2.1/2.3): HER İKİ kulvardaki HER
 * ÇALIŞTIRMA try/catch(Throwable) içine alınır. Çöken bir hook, HİÇBİR
 * ZAMAN çağıran sayfayı 500'e düşürmez; sessizce module_quarantine.log'a
 * yazılır ve akış (sıradaki hook'lar, sayfanın geri kalanı) bozulmadan
 * devam eder.
 */
final class HookManager
{
    /**
     * @param iterable<int, array{hookPoint: string, priority: int, serviceId: string, method: string}> $attributeHooks
     *   HookRegistrationPass tarafından üretilen, derleme zamanında sabit
     *   attribute hook tanımları (cpalius.hook_definitions parametresi).
     * @param ContainerInterface $serviceLocator #[CpHook] taşıyan
     *   servisleri id'leriyle lazy çözen bir ServiceLocator (bkz. services.yaml).
     */
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ContainerInterface $serviceLocator,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly array $attributeHooks,
    ) {
    }

    public function trigger(string $hookPoint, HookContext $context): HookContext
    {
        $context = $this->runFlatFileHooks($hookPoint, $context);
        $context = $this->runAttributeHooks($hookPoint, $context);

        return $context;
    }

    /**
     * Sistemde o an kayıtlı olan TÜM kanca noktalarını (flat-file taraması +
     * attribute tanımları) birleştirilmiş, tekilleştirilmiş bir listede
     * döner. AACP "Kancalar & Hook Gezgini" ekranı için tasarlanmıştır.
     *
     * @return list<array{hookPoint: string, type: 'flat-file'|'attribute', source: string, detail: string}>
     */
    public function discoverAll(): array
    {
        $discovered = [];

        foreach ($this->discoverFlatFileHooks() as $hook) {
            $discovered[] = $hook;
        }

        foreach ($this->attributeHooks as $hook) {
            $discovered[] = [
                'hookPoint' => $hook['hookPoint'],
                'type' => 'attribute',
                'source' => $hook['serviceId'],
                'detail' => sprintf('%s::%s() (öncelik: %d)', $hook['serviceId'], $hook['method'], $hook['priority']),
            ];
        }

        usort($discovered, static fn (array $a, array $b): int => $a['hookPoint'] <=> $b['hookPoint']);

        return $discovered;
    }

    private function runFlatFileHooks(string $hookPoint, HookContext $context): HookContext
    {
        foreach ($this->moduleRegistry->getHealthyModuleBundles() as $moduleClass) {
            $hookFile = $this->resolveModuleHookFile($moduleClass, $hookPoint);

            if ($hookFile === null || !is_file($hookFile)) {
                continue;
            }

            try {
                $context = $this->includeIsolated($hookFile, $context);
            } catch (Throwable $e) {
                $this->quarantineHookFailure($moduleClass, $hookPoint, $hookFile, $e);
            }
        }

        return $context;
    }

    private function runAttributeHooks(string $hookPoint, HookContext $context): HookContext
    {
        $matching = array_filter(
            $this->attributeHooks,
            static fn (array $hook): bool => $hook['hookPoint'] === $hookPoint,
        );

        usort($matching, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($matching as $hook) {
            if (!$this->serviceLocator->has($hook['serviceId'])) {
                continue;
            }

            try {
                $service = $this->serviceLocator->get($hook['serviceId']);
                $method = $hook['method'];
                $result = $service->$method($context);

                if ($result instanceof HookContext) {
                    $context = $result;
                }
            } catch (Throwable $e) {
                $this->quarantineHookFailure($hook['serviceId'], $hookPoint, $hook['serviceId'].'::'.$hook['method'].'()', $e);
            }
        }

        return $context;
    }

    /**
     * Hook dosyasını, dış scope'tan tamamen izole edilmiş, kapatılmış bir
     * Closure içinde include eder. Closure statik olarak tanımlanır (baştan
     * itibaren hiçbir $this bağlamı taşımaz) ve HİÇBİR "use (...)" ile dış
     * scope değişkeni almaz; dosyanın erişebileceği TEK değişken, kendisine
     * parametre olarak verilen yerel $context'tir. Bu, dosyanın include
     * edildiği HookManager metodunun ($this, diğer yerel değişkenler)
     * hiçbirine erişememesini garanti eder.
     */
    private function includeIsolated(string $hookFile, HookContext $context): HookContext
    {
        $isolated = static function (string $__cp_hook_file, HookContext $context): mixed {
            return include $__cp_hook_file;
        };

        $result = $isolated($hookFile, $context);

        return $result instanceof HookContext ? $result : $context;
    }

    private function resolveModuleHookFile(string $moduleClass, string $hookPoint): ?string
    {
        try {
            $reflection = new \ReflectionClass($moduleClass);
            $moduleDir = \dirname((string) $reflection->getFileName());
        } catch (Throwable) {
            return null;
        }

        $safeHookPoint = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $hookPoint) ?? '';

        if ($safeHookPoint === '' || $safeHookPoint !== $hookPoint) {
            // Hook noktası adı beklenmedik karakterler içeriyorsa (path
            // traversal denemesi dahil) dosya sistemine hiç dokunulmaz.
            return null;
        }

        return $moduleDir.'/Hooks/'.$safeHookPoint.'.php';
    }

    /**
     * @return list<array{hookPoint: string, type: 'flat-file', source: string, detail: string}>
     */
    private function discoverFlatFileHooks(): array
    {
        $discovered = [];

        foreach ($this->moduleRegistry->getHealthyModuleBundles() as $moduleClass) {
            try {
                $reflection = new \ReflectionClass($moduleClass);
                $hooksDir = \dirname((string) $reflection->getFileName()).'/Hooks';
            } catch (Throwable) {
                continue;
            }

            if (!is_dir($hooksDir)) {
                continue;
            }

            $files = new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($hooksDir, \FilesystemIterator::SKIP_DOTS)),
                '/\.php$/',
            );

            foreach ($files as $file) {
                if ($this->declaresPhpClass((string) $file->getPathname())) {
                    // Modules\X\Hooks\ dizini, Attribute Kulvarı'nın (#[CpHook])
                    // servis sınıflarıyla AYNI dizini paylaşabilir (bkz.
                    // BlogAttributeHooks örneği). Bir sınıf/interface/trait
                    // TANIMLAYAN dosya asla bir "hook noktası dosyası" değildir
                    // (flat-file dosyaları Cotonti konvansiyonu gereği salt
                    // prosedürel bir `return (closure)($context);` içerir,
                    // hiçbir zaman namespace/class bildirmez) — bu yüzden
                    // flat-file keşfinden HARİÇ TUTULUR.
                    continue;
                }

                $hookPoint = basename((string) $file->getFilename(), '.php');

                $discovered[] = [
                    'hookPoint' => $hookPoint,
                    'type' => 'flat-file',
                    'source' => $moduleClass,
                    'detail' => $file->getPathname(),
                ];
            }
        }

        return $discovered;
    }

    /**
     * Bir PHP dosyasının bir sınıf/interface/trait/enum TANIMLAYIP
     * tanımlamadığını, dosyayı include ETMEDEN (sadece tokenize ederek)
     * tespit eder. Flat-file hook dosyalarını Attribute Kulvarı'nın servis
     * sınıflarından ayırt etmek için kullanılır (bkz. discoverFlatFileHooks()
     * ve resolveModuleHookFile() docblock'ları).
     */
    private function declaresPhpClass(string $file): bool
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return false;
        }

        $tokens = token_get_all($contents);

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                return true;
            }
        }

        return false;
    }

    private function quarantineHookFailure(string $source, string $hookPoint, string $detail, Throwable $e): void
    {
        $this->logger->warning('Hook çalıştırılırken hata oluştu, sessizce atlandı.', [
            'source' => $source,
            'hook_point' => $hookPoint,
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] "%s" kanca noktasındaki %s hata verdiği için çalışma anında atlandı. Sebep: %s',
            date('Y-m-d H:i:s'),
            $hookPoint,
            $detail,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
