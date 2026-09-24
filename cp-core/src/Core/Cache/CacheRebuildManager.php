<?php

declare(strict_types=1);

namespace App\Core\Cache;

use App\Core\Asset\TailwindBuildSeeder;
use App\Core\OriginCache\OriginCachePurger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Psr\Cache\CacheItemPoolInterface;
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
        /**
         * Doctrine's query cache holds the SQL it generated for each DQL query,
         * not the DQL — so a release that renames a table leaves Redis handing
         * out statements that select from tables no longer there. These pools
         * are Redis-backed in every environment (cache.yaml), which means
         * wiping var/cache does not reach them and neither does cache.app.
         */
        #[Autowire(service: 'doctrine.system_cache_pool')]
        private readonly CacheItemPoolInterface $doctrineSystemCache,
        #[Autowire(service: 'doctrine.result_cache_pool')]
        private readonly CacheItemPoolInterface $doctrineResultCache,
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

        // Before the origin HTML, because a page rendered from a stale query
        // plan is exactly what we are trying not to put back into that cache.
        try {
            $this->doctrineSystemCache->clear();
            $this->doctrineResultCache->clear();
            $log[] = $msg['doctrine_cleared'];
        } catch (\Throwable $e) {
            $log[] = str_replace('__ERROR__', $e->getMessage(), $msg['doctrine_failed']);
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

        $this->purgeCacheDirectoryNow();
    }

    /**
     * Does the purge itself, without touching the HTTP response — safe to call
     * mid-request, unlike flushDeferredKernelPurge() which assumes the response
     * already went out. compileMappedAssets() calls this right before it, so
     * the subprocess it spawns reads a container that matches the code just
     * deployed instead of whatever was compiled before this request started.
     */
    private function purgeCacheDirectoryNow(): void
    {
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
     * Tailwind CSS plus the AssetMapper dump. The dump is the one WordPress
     * never needs: their zip already contains built JS. Ours is a git
     * archive, public/assets is gitignored, and a new importmap entrypoint
     * 404s until this runs on the live tree.
     *
     * @return array{success: bool, output: string}
     */
    public function rebuildAssets(): array
    {
        $tailwind = $this->runConsoleCommand(['tailwind:build'], 120);
        $mapped = $this->compileMappedAssets();

        return [
            'success' => $tailwind['success'] && $mapped['success'],
            'output' => trim($tailwind['output'].PHP_EOL.$mapped['output']),
        ];
    }

    /**
     * Write hashed files into public/assets so importmap URLs resolve after
     * a zip overwrite. A separate PHP process, because the HTTP request that
     * just landed the files still holds the old compiled container.
     *
     * @return array{success: bool, output: string}
     */
    public function compileMappedAssets(): array
    {
        // UpdateRunner tests use the real manager; compiling here would write
        // public/assets on every hook assertion and take tens of seconds.
        if ($this->environment === 'test') {
            return [
                'success' => true,
                'output' => self::OK.'asset-map:compile skipped (test).',
            ];
        }

        // A purge requested earlier in this same request (clearSymfonyCache())
        // is deferred to kernel.terminate, but the compile below shells out to
        // a brand-new PHP process right now. Left deferred, that subprocess
        // boots against whatever container was compiled BEFORE this request —
        // i.e. before the just-deployed code — and asset-map:compile fails
        // with "cannot be found in any asset map paths" for anything new.
        // Purging now (not via flushDeferredKernelPurge(), which also tries to
        // finish the HTTP response) fixes the ordering without side effects.
        if ($this->deferKernelPurge) {
            $this->deferKernelPurge = false;
            $this->purgeCacheDirectoryNow();
        }

        // asset-map:compile deletes the manifest before it writes a new one.
        // Running it without the built CSS throws, and the site then 500s on
        // every page because the good manifest from the zip is already gone.
        if (!(new TailwindBuildSeeder($this->projectDir))->seed()) {
            return [
                'success' => true,
                'output' => self::OK.'asset-map:compile skipped; shipped public/assets kept (built Tailwind CSS was not on disk).',
            ];
        }

        $result = $this->runConsoleCommand(['asset-map:compile'], 180);

        if ($result['success']) {
            $result['output'] = $this->ok('aacp.cache_rebuild.log.assets_compiled').PHP_EOL.$result['output'];
        }

        return $result;
    }

    /**
     * What CoreUpdater and PatchInstaller run the moment new files are on
     * disk: wipe the compiled container, then dump the importmap so the next
     * request is not a 404 for a hash the archive never contained.
     *
     * @return list<string>
     */
    public function afterCodeUpdate(): array
    {
        $log = [];

        try {
            $this->clearSymfonyCache();
            $log[] = 'Caches cleared; the next request rebuilds against the current code.';
        } catch (\Throwable $e) {
            $log[] = 'WARNING: cache could not be cleared ('.$e->getMessage()
                .'). Delete cp-core/var/cache/<env> by hand before using the site.';
        }

        try {
            $compiled = $this->compileMappedAssets();
            $log[] = $compiled['success']
                ? 'Frontend assets compiled (importmap hashes written to public/assets).'
                : 'WARNING: frontend assets could not be compiled. New panel JS may 404 until AACP rebuilds assets. '.$compiled['output'];
        } catch (\Throwable $e) {
            $log[] = 'WARNING: frontend assets could not be compiled ('.$e->getMessage().').';
        }

        return $log;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{success: bool, output: string}
     */
    private function runConsoleCommand(array $arguments, int $timeout): array
    {
        // PHP_BINARY may be php-fpm (no CLI args). PhpExecutableFinder picks the real CLI binary.
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $process = new Process(
            [$phpBinary, $this->projectDir.'/cp-core/bin/console', ...$arguments, '--env='.$this->environment],
            $this->projectDir,
            null,
            null,
            $timeout,
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
            'doctrine_cleared' => $ok.$t('aacp.cache_rebuild.log.doctrine_cleared'),
            'doctrine_failed' => $err.$t('aacp.cache_rebuild.log.doctrine_failed', ['error' => '__ERROR__']),
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
