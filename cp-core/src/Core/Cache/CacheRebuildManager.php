<?php

declare(strict_types=1);

namespace App\Core\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Shared cache/OPcache/asset rebuild for AACP and Studio. Three independent methods (Law 2.3).
 * Injects cache.app directly — TaggedIterator on cache.pool would instantiate abstract adapters.
 */
final class CacheRebuildManager
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $appCache,
    ) {
    }

    /**
     * Clear cache.app and safe var/cache/{env} subdirs in-process (do not spawn cache:clear).
     *
     * @return array{success: bool, output: string}
     */
    public function clearSymfonyCache(): array
    {
        $log = [];

        try {
            $this->appCache->clear();
            $log[] = '[OK] Uygulama önbelleği (cache.app) temizlendi.';
        } catch (\Throwable $e) {
            $log[] = sprintf('[HATA] cache.app temizlenemedi: %s', $e->getMessage());
        }

        $log = array_merge($log, $this->purgeCacheDirectory());

        $hasFailure = array_filter($log, static fn (string $line) => str_starts_with($line, '[HATA]')) !== [];

        return [
            'success' => !$hasFailure,
            'output' => implode(PHP_EOL, $log),
        ];
    }

    /**
     * Remove rebuildable cache subdirs (pools, twig, …); never delete compiled container/routing files.
     *
     * @return list<string>
     */
    private function purgeCacheDirectory(): array
    {
        $cacheDir = $this->projectDir.'/cp-core/var/cache/'.$this->environment;
        $filesystem = new Filesystem();

        // Never delete compiled container/routing; only rebuildable content dirs.
        $purgeableSubdirs = ['pools', 'twig', 'asset_mapper', 'doctrine', 'jit', 'profiler'];

        $log = [];
        foreach ($purgeableSubdirs as $subdir) {
            $path = $cacheDir.'/'.$subdir;
            if (!$filesystem->exists($path)) {
                continue;
            }

            try {
                $filesystem->remove($path);
                $log[] = sprintf('[OK] Dizin temizlendi: var/cache/%s/%s', $this->environment, $subdir);
            } catch (\Throwable $e) {
                $log[] = sprintf('[HATA] var/cache/%s/%s silinemedi: %s', $this->environment, $subdir, $e->getMessage());
            }
        }

        return $log;
    }

    /**
     * Reset opcode cache for this PHP worker only; other FPM workers are unchanged.
     *
     * @return array{success: bool, output: string}
     */
    public function resetOpcache(): array
    {
        if (!\function_exists('opcache_reset')) {
            return [
                'success' => false,
                'output' => '[HATA] OPcache eklentisi bu PHP kurulumunda yüklü değil.',
            ];
        }

        $result = @opcache_reset();

        if ($result === false) {
            return [
                'success' => false,
                'output' => '[HATA] opcache_reset() başarısız oldu (opcache.enable=0 olabilir).',
            ];
        }

        return [
            'success' => true,
            'output' => '[OK] OPcache sıfırlandı (yalnızca bu isteği işleyen PHP worker\'ı için geçerlidir).',
        ];
    }

    /**
     * Run `tailwind:build` synchronously via Process (no messenger/queue — YAGNI).
     *
     * @return array{success: bool, output: string}
     */
    public function rebuildAssets(): array
    {
        // PHP_BINARY may be php-fpm (no CLI args). PhpExecutableFinder picks the real CLI binary.
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$phpBinary, $this->projectDir.'/cp-core/bin/console', 'tailwind:build', '--env='.$this->environment],
            $this->projectDir,
            null,
            null,
            120,
        );

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            return [
                'success' => false,
                'output' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'output' => $process->getOutput().$process->getErrorOutput(),
        ];
    }
}
