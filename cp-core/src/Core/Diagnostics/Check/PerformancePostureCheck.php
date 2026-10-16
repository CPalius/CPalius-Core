<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\OriginCache\OriginCacheStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Is the machine configured to run CPalius quickly?
 *
 * Application code can be perfect and still crawl on a PHP that re-reads every
 * file on every request or a database whose working set does not fit in memory.
 * These are the settings that decide that, with the value that was found, the one
 * to use, and why.
 *
 * Reads the PHP it is running in. From the command line that is the CLI's php.ini,
 * which legitimately differs (OPcache is usually off there), so the OPcache and
 * runtime findings say so instead of raising a false alarm; the same check shown
 * in AACP → Performance → Page costs runs inside the web server's PHP and is the
 * one to trust.
 */
final class PerformancePostureCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OriginCacheStore $originCache,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    public function key(): string
    {
        return 'performance';
    }

    public function run(): array
    {
        return [
            ...$this->mode(),
            ...$this->opcache(),
            ...$this->runtime(),
            ...$this->database(),
            ...$this->pageCache(),
        ];
    }

    /** @return list<DoctorFinding> */
    private function mode(): array
    {
        if ($this->environment === 'prod' && !$this->debug) {
            return [DoctorFinding::pass('performance.mode', 'Runtime mode', 'prod, debug off.')];
        }

        return [new DoctorFinding(
            'performance.mode',
            $this->environment === 'prod' ? DoctorFinding::SEVERITY_HIGH : DoctorFinding::SEVERITY_LOW,
            'Runtime mode',
            \sprintf('APP_ENV=%s, APP_DEBUG=%s. Debug mode profiles every request and rebuilds caches when files change; it is several times slower than production mode.', $this->environment, $this->debug ? '1' : '0'),
            $this->environment === 'prod' ? 'Set APP_DEBUG=0.' : 'Expected on a development machine. On a live site set APP_ENV=prod and APP_DEBUG=0.',
        )];
    }

    /** @return list<DoctorFinding> */
    private function opcache(): array
    {
        $cli = \PHP_SAPI === 'cli';
        if ($cli && !filter_var(ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL)) {
            return [DoctorFinding::pass('performance.opcache', 'OPcache', 'Off for the command line, as usual. Open AACP → Performance → Page costs to see what the web server\'s PHP uses.')];
        }
        if (!\extension_loaded('Zend OPcache') || !filter_var(ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)) {
            return [new DoctorFinding(
                'performance.opcache',
                DoctorFinding::SEVERITY_CRITICAL,
                'OPcache is off',
                'Without OPcache PHP parses and compiles every file of the framework on every request. This is the single largest speed-up available and costs nothing.',
                'Enable the opcache extension and set opcache.enable=1.',
            )];
        }

        $findings = [];
        $files = $this->phpFileCount();
        $maxFiles = (int) ini_get('opcache.max_accelerated_files');
        $memory = $this->bytes((string) ini_get('opcache.memory_consumption'), 1048576);
        $strings = (int) ini_get('opcache.interned_strings_buffer');

        $findings[] = $maxFiles >= (int) ($files * 1.3)
            ? DoctorFinding::pass('performance.opcache.files', 'OPcache file slots', \sprintf('%d slots for ~%d PHP files.', $maxFiles, $files))
            : new DoctorFinding('performance.opcache.files', DoctorFinding::SEVERITY_HIGH, 'OPcache has too few file slots', \sprintf('opcache.max_accelerated_files=%d, but the project and its vendors hold ~%d PHP files. Files beyond the limit are recompiled on every request.', $maxFiles, $files), 'Set opcache.max_accelerated_files to at least '.max(20000, (int) ($files * 1.5)).'.');

        $findings[] = $memory >= 192 * 1048576
            ? DoctorFinding::pass('performance.opcache.memory', 'OPcache memory', \sprintf('%d MB.', $memory / 1048576))
            : new DoctorFinding('performance.opcache.memory', DoctorFinding::SEVERITY_MEDIUM, 'OPcache memory is small', \sprintf('opcache.memory_consumption=%d MB. When it fills, OPcache restarts and everything is compiled again.', $memory / 1048576), 'Set opcache.memory_consumption=256.');

        if ($strings < 16) {
            $findings[] = new DoctorFinding('performance.opcache.strings', DoctorFinding::SEVERITY_LOW, 'OPcache interned strings buffer is small', \sprintf('opcache.interned_strings_buffer=%d MB.', $strings), 'Set opcache.interned_strings_buffer=16 or more.');
        }

        if (filter_var(ini_get('opcache.validate_timestamps'), \FILTER_VALIDATE_BOOL) && (int) ini_get('opcache.revalidate_freq') < 10) {
            $findings[] = new DoctorFinding('performance.opcache.revalidate', DoctorFinding::SEVERITY_LOW, 'OPcache checks files for changes too often', \sprintf('opcache.revalidate_freq=%d s: a stat() of every included file that often.', (int) ini_get('opcache.revalidate_freq')), 'Set opcache.revalidate_freq=60, or opcache.validate_timestamps=0 on a site that is updated through AACP (the updater resets OPcache).');
        }

        if ((string) ini_get('opcache.preload') === '') {
            $findings[] = new DoctorFinding('performance.opcache.preload', DoctorFinding::SEVERITY_LOW, 'OPcache preloading is not used', 'Preloading keeps the framework\'s classes compiled and linked in memory from the first request.', 'Set opcache.preload to cp-core/config/preload.php and opcache.preload_user to the PHP user (needs a PHP restart, not available on every host).');
        }

        return $findings;
    }

    /** @return list<DoctorFinding> */
    private function runtime(): array
    {
        $findings = [];

        $realpath = $this->bytes((string) ini_get('realpath_cache_size'), 1);
        $findings[] = $realpath >= 4 * 1048576
            ? DoctorFinding::pass('performance.realpath', 'realpath cache', \sprintf('%d KB, ttl %d s.', $realpath / 1024, (int) ini_get('realpath_cache_ttl')))
            : new DoctorFinding('performance.realpath', DoctorFinding::SEVERITY_MEDIUM, 'realpath cache is small', \sprintf('realpath_cache_size=%d KB. A framework resolves thousands of paths per request; a small cache turns each into a filesystem call.', $realpath / 1024), 'Set realpath_cache_size=4096K and realpath_cache_ttl=600.');

        if (!\extension_loaded('apcu') || !filter_var(ini_get('apc.enabled'), \FILTER_VALIDATE_BOOL)) {
            $findings[] = new DoctorFinding('performance.apcu', DoctorFinding::SEVERITY_LOW, 'APCu is not available', 'APCu is a shared in-memory cache inside PHP; the framework\'s own caches use it when present and fall back to files without it.', 'Install and enable the apcu extension (apc.enabled=1; apc.enable_cli stays 0).');
        }

        $findings[] = \extension_loaded('zlib')
            ? DoctorFinding::pass('performance.zlib', 'zlib', 'Available: cached pages are stored pre-compressed.')
            : new DoctorFinding('performance.zlib', DoctorFinding::SEVERITY_LOW, 'zlib is missing', 'Cached pages cannot be stored pre-compressed, so the web server compresses each one on every hit.', 'Enable the zlib extension.');

        $memory = $this->bytes((string) ini_get('memory_limit'), 1);
        if ($memory !== -1 && $memory < 256 * 1048576 && \PHP_SAPI !== 'cli') {
            $findings[] = new DoctorFinding('performance.memory_limit', DoctorFinding::SEVERITY_LOW, 'memory_limit is low', \sprintf('memory_limit=%d MB.', $memory / 1048576), 'Set memory_limit=256M or more for the web PHP.');
        }

        return $findings;
    }

    /** @return list<DoctorFinding> */
    private function database(): array
    {
        try {
            $platform = $this->connection->getDatabasePlatform()::class;
            if (!str_contains($platform, 'MySQL') && !str_contains($platform, 'MariaDB')) {
                return [];
            }
            $vars = $this->connection->fetchAllKeyValue("SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','max_connections','innodb_flush_log_at_trx_commit','slow_query_log','innodb_log_file_size','innodb_redo_log_capacity')");
            $dataBytes = (int) $this->connection->fetchOne('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
        } catch (\Throwable) {
            return [];
        }

        $findings = [];
        $pool = (int) ($vars['innodb_buffer_pool_size'] ?? 0);
        $findings[] = $pool >= $dataBytes || $pool >= 2 * 1073741824
            ? DoctorFinding::pass('performance.db.buffer_pool', 'InnoDB buffer pool', \sprintf('%d MB for %d MB of data and indexes.', $pool / 1048576, $dataBytes / 1048576))
            : new DoctorFinding('performance.db.buffer_pool', DoctorFinding::SEVERITY_HIGH, 'InnoDB buffer pool is smaller than the database', \sprintf('innodb_buffer_pool_size=%d MB, database %d MB. Reads that miss the pool go to disk; the working set should fit in memory.', $pool / 1048576, $dataBytes / 1048576), 'Raise innodb_buffer_pool_size to the database size plus growth (up to ~70% of RAM on a dedicated database server).');

        if ((int) ($vars['max_connections'] ?? 0) > 0 && (int) $vars['max_connections'] < 150) {
            $findings[] = new DoctorFinding('performance.db.connections', DoctorFinding::SEVERITY_MEDIUM, 'Few database connections allowed', \sprintf('max_connections=%d. Every busy PHP worker holds one; a traffic peak larger than this is answered with errors.', (int) $vars['max_connections']), 'Set max_connections at least to the number of PHP workers plus 20%, and size workers to the CPU.');
        }

        return $findings;
    }

    /** @return list<DoctorFinding> */
    private function pageCache(): array
    {
        $findings = [];

        if (!$this->originCache->isEnabledOnDisk()) {
            $findings[] = new DoctorFinding('performance.page_cache', DoctorFinding::SEVERITY_HIGH, 'The page cache is off', 'Every anonymous visit is rendered by PHP and the database. With the cache on, Apache answers repeat visits from a file.', 'AACP → Performance → CPalius cache → enable.');
        } else {
            $findings[] = DoctorFinding::pass('performance.page_cache', 'Page cache', 'On.');
            $free = @disk_free_space($this->originCache->root());
            if (\is_float($free) && $free < 1073741824) {
                $findings[] = new DoctorFinding('performance.page_cache.disk', DoctorFinding::SEVERITY_MEDIUM, 'Little disk space for the page cache', \sprintf('%d MB free where the snapshots are written.', $free / 1048576), 'Free some space; a full disk silently stops new pages from being cached.');
            }
        }

        $htaccess = (string) @file_get_contents($this->projectDir.'/public/.htaccess');
        if ($htaccess !== '' && !str_contains($htaccess, 'immutable')) {
            $findings[] = new DoctorFinding('performance.static_headers', DoctorFinding::SEVERITY_MEDIUM, 'Static files carry no cache headers', 'public/.htaccess has none of the Cache-Control rules for CSS, scripts, fonts and images, so browsers revalidate each of them on every page.', 'Restore the static-files block of the shipped public/.htaccess (needs mod_headers).');
        }

        return $findings;
    }

    private function phpFileCount(): int
    {
        static $count = null;
        if ($count !== null) {
            return $count;
        }
        $count = 0;
        foreach (['cp-core/src', 'cp-includes/vendor', 'cp-content/modules'] as $dir) {
            $path = $this->projectDir.'/'.$dir;
            if (!is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /** "256M", "4096K", "1G", "-1" or a plain number to bytes; $default is the unit of a bare number. */
    private function bytes(string $value, int $default): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $number = (float) $value;

        return (int) match (strtoupper(substr($value, -1))) {
            'G' => $number * 1073741824,
            'M' => $number * 1048576,
            'K' => $number * 1024,
            default => $number * $default,
        };
    }
}
