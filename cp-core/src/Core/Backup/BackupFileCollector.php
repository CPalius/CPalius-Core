<?php

declare(strict_types=1);

namespace App\Core\Backup;

/**
 * Walks the project root for a disaster-recovery ZIP.
 * Secrets, VCS, nested backups, and rebuildable cache stay out; vendor and public stay in.
 */
final class BackupFileCollector
{
    /** @var list<string> */
    private const EXCLUDED_PREFIXES = [
        'cp-core/var/backups/',
        'cp-core/var/cache/',
        'cp-core/var/sessions/',
        'cp-core/var/tailwind/',
        'public/page-cache/',
    ];

    /** @var list<string> */
    private const EXCLUDED_SEGMENTS = [
        '.git',
        'node_modules',
        '.idea',
        '.cache',
        '.composer',
        '.config',
        '.local',
        '.npm',
        '.ssh',
    ];

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<array{absolute: string, relative: string}>
     */
    public function collect(): array
    {
        $root = $this->normalizeDir($this->projectDir);
        if (!is_dir($root)) {
            return [];
        }

        $entries = [];
        $inner = new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $inner,
            function (\SplFileInfo $current) use ($root): bool {
                $relative = $this->toRelative($root, $current->getPathname());
                if ($relative === null) {
                    return false;
                }

                if ($current->isDir()) {
                    return $current->isReadable() && $this->shouldDescend($relative);
                }

                return $this->shouldInclude($relative);
            },
        );

        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = $this->toRelative($root, $file->getPathname());
            if ($relative === null || !$this->shouldInclude($relative)) {
                continue;
            }

            $entries[] = ['absolute' => $file->getPathname(), 'relative' => $relative];
        }

        return $entries;
    }

    public function shouldInclude(string $relativePosix): bool
    {
        $relative = str_replace('\\', '/', ltrim($relativePosix, '/'));
        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }

        $base = basename($relative);
        if ($base === '.env' || str_starts_with($base, '.env.')) {
            return false;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                return false;
            }
        }

        foreach (explode('/', $relative) as $segment) {
            if (\in_array($segment, self::EXCLUDED_SEGMENTS, true)) {
                return false;
            }
        }

        return true;
    }

    private function shouldDescend(string $relativePosix): bool
    {
        $relative = str_replace('\\', '/', trim($relativePosix, '/'));
        if ($relative === '') {
            return true;
        }

        foreach (self::EXCLUDED_SEGMENTS as $segment) {
            if ($relative === $segment || str_starts_with($relative, $segment.'/')) {
                return false;
            }
        }

        $asDir = $relative.'/';
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($asDir === $prefix || str_starts_with($asDir, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function toRelative(string $root, string $absolute): ?string
    {
        $normalized = str_replace('\\', '/', $absolute);
        $prefix = $root.'/';
        if ($normalized === $root) {
            return '';
        }
        if (!str_starts_with($normalized, $prefix)) {
            return null;
        }

        return ltrim(substr($normalized, strlen($root)), '/');
    }

    private function normalizeDir(string $dir): string
    {
        return rtrim(str_replace('\\', '/', $dir), '/');
    }
}
