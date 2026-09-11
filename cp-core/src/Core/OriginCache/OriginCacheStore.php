<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Disk store for anonymous HTML snapshots under public/page-cache.
 */
final class OriginCacheStore
{
    public const SENTINEL = '.enabled';

    public const BANNER = '<!-- CPalius Performance System — Active -->';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function root(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'public'.\DIRECTORY_SEPARATOR.'page-cache';
    }

    public function isEnabledOnDisk(): bool
    {
        return is_file($this->root().\DIRECTORY_SEPARATOR.self::SENTINEL);
    }

    public function enable(): void
    {
        $this->ensureRoot();
        file_put_contents($this->root().\DIRECTORY_SEPARATOR.self::SENTINEL, '1');
    }

    public function disable(): void
    {
        $sentinel = $this->root().\DIRECTORY_SEPARATOR.self::SENTINEL;
        if (is_file($sentinel)) {
            @unlink($sentinel);
        }
    }

    public function htmlPath(string $pathInfo, string $queryString = '', ?OriginCacheVaryContext $ctx = null): string
    {
        $relative = $this->relativeHtmlPath($pathInfo, $queryString, $ctx);

        return $this->root().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relative);
    }

    public function relativeHtmlPath(string $pathInfo, string $queryString = '', ?OriginCacheVaryContext $ctx = null): string
    {
        $trimmed = trim($pathInfo, '/');
        $rel = $trimmed === '' ? '_root' : $trimmed;
        $rel = str_replace(['..', "\0"], '', $rel);
        $rel = preg_replace('#/+#', '/', $rel) ?? $rel;

        if ($ctx !== null) {
            $rel = $ctx->pathPrefix().'/'.$rel;
        }

        if ($queryString !== '') {
            return $rel.'/q-'.hash('sha256', $queryString).'.html';
        }

        return $rel.'/index.html';
    }

    /**
     * $ttlOverride: T2.3 per-response max-age (CacheTagCollector::setMaxAge()) — null
     * means "use the global TTL", same as before this parameter existed.
     */
    public function putHtml(
        string $pathInfo,
        string $queryString,
        string $html,
        ?int $ttlOverride = null,
        ?OriginCacheVaryContext $ctx = null,
    ): bool
    {
        $file = $this->htmlPath($pathInfo, $queryString, $ctx);
        $dir = \dirname($file);
        if (!$this->ensureDir($dir)) {
            return false;
        }

        if ($ttlOverride !== null && $ttlOverride > 0) {
            @file_put_contents($file.'.ttl', (string) $ttlOverride, LOCK_EX);
        } else {
            @unlink($file.'.ttl');
        }

        return @file_put_contents($file, $html, LOCK_EX) !== false;
    }

    public function getHtml(
        string $pathInfo,
        string $queryString,
        int $ttl,
        ?OriginCacheVaryContext $ctx = null,
    ): ?string
    {
        $file = $this->htmlPath($pathInfo, $queryString, $ctx);
        if (!is_file($file)) {
            // Legacy pre-GC1 snapshots (no _ctx prefix) — serve once, then they age out.
            if ($ctx !== null) {
                return $this->getHtml($pathInfo, $queryString, $ttl, null);
            }

            return null;
        }

        $effectiveTtl = $this->effectiveTtl($file, $ttl);
        if ($effectiveTtl > 0 && (time() - (int) filemtime($file)) > $effectiveTtl) {
            @unlink($file);
            @unlink($file.'.ttl');

            return null;
        }

        $html = @file_get_contents($file);

        return $html === false ? null : $html;
    }

    private function effectiveTtl(string $file, int $globalTtl): int
    {
        $override = @file_get_contents($file.'.ttl');
        if ($override === false) {
            return $globalTtl;
        }

        $seconds = (int) $override;

        return $seconds > 0 ? $seconds : $globalTtl;
    }

    public function putAsset(string $filename, string $contents): ?string
    {
        $dir = $this->root().\DIRECTORY_SEPARATOR.'assets';
        if (!$this->ensureDir($dir)) {
            return null;
        }
        $file = $dir.\DIRECTORY_SEPARATOR.$filename;
        if (!is_file($file) && @file_put_contents($file, $contents, LOCK_EX) === false) {
            return null;
        }

        return '/page-cache/assets/'.$filename;
    }

    public function putImage(string $filename, string $contents): ?string
    {
        $dir = $this->root().\DIRECTORY_SEPARATOR.'img';
        if (!$this->ensureDir($dir)) {
            return null;
        }
        $file = $dir.\DIRECTORY_SEPARATOR.$filename;
        if (!is_file($file) && @file_put_contents($file, $contents, LOCK_EX) === false) {
            return null;
        }

        return '/page-cache/img/'.$filename;
    }

    public function deleteExact(string $pathInfo): int
    {
        $deleted = 0;
        // Legacy (pre-GC1) path.
        $deleted += $this->deleteExactAt($this->htmlPath($pathInfo, ''));

        // Every locale × visibility variant under _ctx/.
        $trimmed = trim($pathInfo, '/');
        $rel = $trimmed === '' ? '_root' : $trimmed;
        $rel = str_replace(['..', "\0"], '', $rel);
        $rel = preg_replace('#/+#', '/', $rel) ?? $rel;
        $ctxRoot = $this->root().\DIRECTORY_SEPARATOR.'_ctx';
        if (!is_dir($ctxRoot)) {
            return $deleted;
        }

        foreach (glob($ctxRoot.\DIRECTORY_SEPARATOR.'*') ?: [] as $localeDir) {
            if (!is_dir($localeDir)) {
                continue;
            }
            foreach (glob($localeDir.\DIRECTORY_SEPARATOR.'*') ?: [] as $visDir) {
                if (!is_dir($visDir)) {
                    continue;
                }
                $index = $visDir.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $rel).\DIRECTORY_SEPARATOR.'index.html';
                $deleted += $this->deleteExactAt($index);
            }
        }

        return $deleted;
    }

    private function deleteExactAt(string $index): int
    {
        $deleted = 0;
        if (is_file($index) && @unlink($index)) {
            ++$deleted;
        }
        @unlink($index.'.ttl');
        $dir = \dirname($index);
        foreach (glob($dir.\DIRECTORY_SEPARATOR.'q-*.html') ?: [] as $file) {
            if (@unlink($file)) {
                ++$deleted;
            }
            @unlink($file.'.ttl');
        }

        return $deleted;
    }

    public function deletePrefix(string $pathPrefix): int
    {
        $trimmed = trim($pathPrefix, '/');
        $dir = $trimmed === ''
            ? $this->root()
            : $this->root().\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $trimmed);
        if (!is_dir($dir)) {
            return 0;
        }

        return $this->deleteTree($dir, keepRoot: $dir === $this->root());
    }

    public function purgeExpired(int $ttl): int
    {
        if ($ttl <= 0 || !is_dir($this->root())) {
            return 0;
        }

        $deleted = 0;
        $cutoff = time() - $ttl;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if ($name === self::SENTINEL || $name === '.htaccess' || $name === '.gitkeep') {
                continue;
            }
            if ($file->getMTime() < $cutoff) {
                if (@unlink($file->getPathname())) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    public function purgeAll(): int
    {
        if (!is_dir($this->root())) {
            return 0;
        }

        return $this->deleteTree($this->root(), keepRoot: true);
    }

    public function probeWrite(): bool
    {
        $this->ensureRoot();
        $file = $this->root().\DIRECTORY_SEPARATOR.'.probe';
        $ok = @file_put_contents($file, 'ok', LOCK_EX) !== false;
        if (is_file($file)) {
            @unlink($file);
        }

        return $ok;
    }

    private function ensureRoot(): void
    {
        $this->ensureDir($this->root());
        $this->ensureDir($this->root().\DIRECTORY_SEPARATOR.'assets');
        $this->ensureDir($this->root().\DIRECTORY_SEPARATOR.'img');
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    private function deleteTree(string $dir, bool $keepRoot): int
    {
        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if ($name === self::SENTINEL || $name === '.htaccess' || $name === '.gitkeep') {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($file->getPathname());
                continue;
            }
            if (@unlink($file->getPathname())) {
                ++$deleted;
            }
        }

        if (!$keepRoot) {
            @rmdir($dir);
        }

        return $deleted;
    }

    /**
     * Disk inventory for the AACP performance panel. Caps the walk at 8000 files.
     *
     * @return array{
     *     enabled: bool,
     *     htmlCount: int,
     *     htmlBytes: int,
     *     assetCount: int,
     *     assetBytes: int,
     *     imageCount: int,
     *     imageBytes: int,
     *     totalBytes: int,
     *     pages: list<array{path: string, bytes: int, mtime: int}>
     * }
     */
    public function summarize(int $pageLimit = 15): array
    {
        $empty = [
            'enabled' => $this->isEnabledOnDisk(),
            'htmlCount' => 0,
            'htmlBytes' => 0,
            'assetCount' => 0,
            'assetBytes' => 0,
            'imageCount' => 0,
            'imageBytes' => 0,
            'totalBytes' => 0,
            'pages' => [],
        ];

        $root = $this->root();
        if (!is_dir($root)) {
            return $empty;
        }

        $htmlCount = 0;
        $htmlBytes = 0;
        $assetCount = 0;
        $assetBytes = 0;
        $imageCount = 0;
        $imageBytes = 0;
        $pages = [];
        $walked = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            ++$walked;
            if ($walked > 8000) {
                break;
            }

            $name = $file->getFilename();
            if ($name === self::SENTINEL || $name === '.htaccess' || $name === '.gitkeep' || $name === '.probe') {
                continue;
            }

            $size = (int) $file->getSize();
            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root)));
            $relative = ltrim($relative, '/');
            $ext = strtolower($file->getExtension());

            if (str_starts_with($relative, 'assets/')) {
                ++$assetCount;
                $assetBytes += $size;
                continue;
            }
            if (str_starts_with($relative, 'img/')) {
                ++$imageCount;
                $imageBytes += $size;
                continue;
            }
            if ($ext !== 'html') {
                continue;
            }

            ++$htmlCount;
            $htmlBytes += $size;
            $pages[] = [
                'path' => $this->publicPathFromRelative($relative),
                'bytes' => $size,
                'mtime' => (int) $file->getMTime(),
            ];
        }

        usort($pages, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $pages = \array_slice($pages, 0, max(1, $pageLimit));

        $empty['enabled'] = $this->isEnabledOnDisk();
        $empty['htmlCount'] = $htmlCount;
        $empty['htmlBytes'] = $htmlBytes;
        $empty['assetCount'] = $assetCount;
        $empty['assetBytes'] = $assetBytes;
        $empty['imageCount'] = $imageCount;
        $empty['imageBytes'] = $imageBytes;
        $empty['totalBytes'] = $htmlBytes + $assetBytes + $imageBytes;
        $empty['pages'] = $pages;

        return $empty;
    }

    private function publicPathFromRelative(string $relative): string
    {
        if (str_ends_with($relative, '/index.html')) {
            $path = substr($relative, 0, -11);
        } elseif (preg_match('#/q-[a-f0-9]+\.html$#', $relative) === 1) {
            $path = preg_replace('#/q-[a-f0-9]+\.html$#', '', $relative).'?…';
        } else {
            $path = $relative;
        }

        if ($path === '_root' || $path === '') {
            return '/';
        }

        return '/'.$path;
    }
}
