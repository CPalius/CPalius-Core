<?php

declare(strict_types=1);

namespace App\Core\Cache;

use App\Core\OriginCache\OriginCachePurger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared cache/OPcache/asset rebuild for AACP and Studio. Three independent methods (Law 2.3).
 * Injects cache.app directly — TaggedIterator on cache.pool would instantiate abstract adapters.
 */
final class CacheRebuildManager
{
    private const OK = '[OK] ';
    private const ERR = '[ERR] ';

    /**
     * Marker written when the deferred purge could not do its job.
     *
     * A failed purge used to be completely silent: the shutdown hook swallowed
     * every error, the operator had already been shown "caches cleared", and the
     * site kept serving a container that no longer matched the code on disk —
     * with no way to tell that apart from "nothing was wrong". The file is the
     * signal; the updates screen reads it and says so.
     */
    public const PURGE_FAILURE_MARKER = 'cache-purge-failed.json';

    private bool $deferKernelPurge = false;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $appCache,
        private readonly OriginCachePurger $originCachePurger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Clear cache.app and origin HTML now. Kernel var/cache/{env} (container,
     * routing, twig) is wiped after the HTTP response is sent — renaming that
     * directory mid-request fatals PHP and returns an HTML 500 instead of JSON.
     *
     * @return array{success: bool, output: string}
     */
    public function clearSymfonyCache(): array
    {
        $msg = $this->translateLogTemplates();
        $log = [];

        try {
            $this->appCache->clear();
            $log[] = $msg['app_cleared'];
        } catch (\Throwable $e) {
            $log[] = str_replace('__ERROR__', $e->getMessage(), $msg['app_failed']);
        }

        try {
            $originDeleted = $this->originCachePurger->purgeAll();
            $log[] = str_replace('__COUNT__', (string) $originDeleted, $msg['origin_cleared']);
        } catch (\Throwable $e) {
            $log[] = str_replace('__ERROR__', $e->getMessage(), $msg['origin_failed']);
        }

        $this->deferKernelPurge = true;
        $log[] = $msg['dir_wiped'];

        if (\function_exists('opcache_reset')) {
            $log[] = $msg['opcache_reset'];
        }

        // Shutdown always runs, even when the compiled container does not yet
        // contain CacheRebuildTerminateSubscriber (chicken-and-egg on first deploy).
        $manager = $this;
        register_shutdown_function(static function () use ($manager): void {
            $manager->flushDeferredKernelPurge();
        });

        return [
            'success' => !$this->logHasError($log),
            'output' => implode(PHP_EOL, $log),
        ];
    }

    /**
     * Called from kernel.terminate after the JSON/HTML response is flushed.
     */
    public function flushDeferredKernelPurge(): void
    {
        if (!$this->deferKernelPurge) {
            return;
        }
        $this->deferKernelPurge = false;

        if (\function_exists('fastcgi_finish_request')) {
            @\fastcgi_finish_request();
        }

        $problem = null;

        try {
            $this->purgeKernelCacheDirectory();

            // Verifying instead of trusting: every filesystem call in the purge
            // is silenced with @, so "no exception" says nothing at all about
            // whether the directory is actually gone.
            $leftover = $this->compiledContainerFilesIn($this->cacheDir);

            if ($leftover > 0) {
                $problem = sprintf('%d compiled container files could not be deleted', $leftover);
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }

        if (\function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->recordPurgeOutcome($problem);
    }

    /**
     * The reason the last deferred purge failed, or null when it worked.
     *
     * Read by the updates screen on the request AFTER a patch — which is the
     * first moment anyone could be told, because the purge runs at the shutdown
     * of the request that applied it.
     */
    public function lastPurgeFailure(): ?string
    {
        $marker = $this->purgeMarkerPath();

        if (!is_file($marker)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($marker), true);

        return \is_array($decoded) && \is_string($decoded['reason'] ?? null)
            ? $decoded['reason']
            : 'unknown';
    }

    public function clearPurgeFailure(): void
    {
        @unlink($this->purgeMarkerPath());
    }

    private function recordPurgeOutcome(?string $problem): void
    {
        $marker = $this->purgeMarkerPath();

        if ($problem === null) {
            @unlink($marker);

            return;
        }

        @mkdir(\dirname($marker), 0775, true);
        @file_put_contents($marker, json_encode([
            'reason' => $problem,
            'cache_dir' => $this->cacheDir,
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
    }

    private function purgeMarkerPath(): string
    {
        return $this->projectDir.'/cp-core/var/update/'.self::PURGE_FAILURE_MARKER;
    }

    /**
     * How many compiled service-factory files survive in the cache dir.
     *
     * Counting these rather than "is the directory empty": Symfony recreates the
     * directory and starts writing a fresh container the moment the next request
     * boots, so emptiness is not the property that matters. A leftover compiled
     * factory is.
     */
    private function compiledContainerFilesIn(string $cacheDir): int
    {
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $count = 0;

        foreach (glob($cacheDir.'/Container*/*.php') ?: [] as $file) {
            if (is_file($file)) {
                ++$count;
            }
        }

        return $count;
    }

    private function purgeKernelCacheDirectory(): void
    {
        $cacheDir = rtrim($this->cacheDir, '/\\');
        $filesystem = new Filesystem();

        if (!$filesystem->exists($cacheDir)) {
            return;
        }

        $this->removeStaleCacheDirs($filesystem, dirname($cacheDir));

        $staleDir = dirname($cacheDir).DIRECTORY_SEPARATOR.$this->environment.'.stale.'.bin2hex(random_bytes(4));
        if (@rename($cacheDir, $staleDir)) {
            @mkdir($cacheDir, 0775, true);
            try {
                $filesystem->remove($staleDir);
            } catch (\Throwable) {
            }

            return;
        }

        $this->deleteTreeContents($cacheDir);
    }

    private function deleteTreeContents(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteTreeContents($path);
                @rmdir($path);
                continue;
            }

            @chmod($path, 0666);
            @unlink($path);
        }
    }

    private function removeStaleCacheDirs(Filesystem $filesystem, string $parentDir): void
    {
        $pattern = $parentDir.DIRECTORY_SEPARATOR.$this->environment.'.stale.*';
        foreach (glob($pattern) ?: [] as $stale) {
            try {
                $filesystem->remove($stale);
            } catch (\Throwable) {
            }
        }
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
                'output' => $this->err('aacp.cache_rebuild.log.opcache_missing'),
            ];
        }

        $result = @opcache_reset();

        if ($result === false) {
            return [
                'success' => false,
                'output' => $this->err('aacp.cache_rebuild.log.opcache_reset_failed'),
            ];
        }

        return [
            'success' => true,
            'output' => $this->ok('aacp.cache_rebuild.log.opcache_reset_worker'),
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

    /**
     * @return array<string, string>
     */
    private function translateLogTemplates(): array
    {
        $env = $this->environment;
        $ok = self::OK;
        $err = self::ERR;
        $t = fn (string $id, array $params = []): string => $this->translator->trans($id, $params);

        return [
            'app_cleared' => $ok.$t('aacp.cache_rebuild.log.app_cleared'),
            'app_failed' => $err.$t('aacp.cache_rebuild.log.app_failed', ['error' => '__ERROR__']),
            'origin_cleared' => $ok.$t('aacp.cache_rebuild.log.origin_cleared', ['count' => '__COUNT__']),
            'origin_failed' => $err.$t('aacp.cache_rebuild.log.origin_failed', ['error' => '__ERROR__']),
            'opcache_reset' => $ok.$t('aacp.cache_rebuild.log.opcache_reset'),
            'dir_wiped' => $ok.$t('aacp.cache_rebuild.log.dir_wiped', ['env' => $env]),
        ];
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function ok(string $id, array $parameters = []): string
    {
        return self::OK.$this->translator->trans($id, $parameters);
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function err(string $id, array $parameters = []): string
    {
        return self::ERR.$this->translator->trans($id, $parameters);
    }

    /**
     * @param list<string> $log
     */
    private function logHasError(array $log): bool
    {
        foreach ($log as $line) {
            if (str_starts_with($line, self::ERR) || str_starts_with($line, '[HATA]')) {
                return true;
            }
        }

        return false;
    }
}
