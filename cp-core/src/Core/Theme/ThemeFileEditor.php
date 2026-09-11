<?php

declare(strict_types=1);

namespace App\Core\Theme;

/**
 * Lists and writes Twig/CSS/JS inside a theme package. Never leaves cp-content/themes/{dir}/Resources.
 */
final class ThemeFileEditor
{
    private const MAX_BYTES = 524288;

    public function __construct(
        private readonly ThemeSourceLinter $linter,
        private readonly string $projectDir,
        private readonly string $themesDir,
    ) {
    }

    /**
     * @return list<array{path: string, kind: string, label: string}>
     */
    public function listFiles(ThemeDefinition $theme): array
    {
        $root = $this->resourcesRoot($theme);
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = $this->toRelative($root, $file->getPathname());
            $kind = $this->kindFromRelative($relative);
            if ($kind === null) {
                continue;
            }

            $files[] = [
                'path' => $relative,
                'kind' => $kind,
                'label' => $relative,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    /**
     * @return array{path: string, kind: string, content: string, bytes: int}
     */
    public function read(ThemeDefinition $theme, string $relative): array
    {
        $absolute = $this->absolutePath($theme, $relative);
        $kind = $this->kindFromRelative($relative);
        if ($kind === null) {
            throw new \InvalidArgumentException('That theme file type is not editable.');
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new \RuntimeException('The theme file could not be read.');
        }

        return [
            'path' => $relative,
            'kind' => $kind,
            'content' => $content,
            'bytes' => strlen($content),
        ];
    }

    /**
     * @return list<string>
     */
    public function lint(string $kind, string $content, string $label): array
    {
        return $this->linter->problems($kind, $content, $label);
    }

    /**
     * @return list<string> Lint problems after a successful write (empty when clean).
     */
    public function write(ThemeDefinition $theme, string $relative, string $content, bool $force): array
    {
        $absolute = $this->absolutePath($theme, $relative);
        $kind = $this->kindFromRelative($relative);
        if ($kind === null) {
            throw new \InvalidArgumentException('That theme file type is not editable.');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Theme files larger than 512 KiB cannot be saved from AACP.');
        }

        $problems = $this->lint($kind, $content, $relative);
        if ($problems !== [] && !$force) {
            return $problems;
        }

        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The theme directory could not be created.');
        }

        $tmp = $absolute.'.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('The theme file could not be written.');
        }
        if (!@rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new \RuntimeException('The theme file could not be replaced.');
        }

        $this->publishPublicCopy($theme, $relative, $content);

        return $problems;
    }

    public function absolutePath(ThemeDefinition $theme, string $relative): string
    {
        $relative = $this->normalizeRelative($relative);
        $kind = $this->kindFromRelative($relative);
        if ($kind === null) {
            throw new \InvalidArgumentException('That theme file type is not editable.');
        }

        $root = $this->resourcesRoot($theme);
        $candidate = $root.'/'.$relative;
        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw new \InvalidArgumentException('The theme resources directory is missing.');
        }

        $realFile = realpath($candidate);
        if ($realFile === false) {
            $parent = realpath(dirname($candidate));
            if ($parent === false || !str_starts_with(str_replace('\\', '/', $parent), str_replace('\\', '/', $realRoot))) {
                throw new \InvalidArgumentException('That path is outside the theme.');
            }

            return $candidate;
        }

        $rootN = str_replace('\\', '/', $realRoot);
        $fileN = str_replace('\\', '/', $realFile);
        if ($fileN !== $rootN && !str_starts_with($fileN, $rootN.'/')) {
            throw new \InvalidArgumentException('That path is outside the theme.');
        }

        return $realFile;
    }

    public function kindFromRelative(string $relative): ?string
    {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        if (str_ends_with($relative, '.html.twig') || str_ends_with($relative, '.twig')) {
            return 'twig';
        }
        if (str_ends_with($relative, '.css')) {
            return 'css';
        }
        if (str_ends_with($relative, '.js')) {
            return 'js';
        }

        return null;
    }

    private function normalizeRelative(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('That path is not allowed.');
        }

        if (preg_match('#^(views|dist|assets)/[A-Za-z0-9][A-Za-z0-9._/-]*\.(html\.twig|twig|css|js)$#', $relative) !== 1) {
            throw new \InvalidArgumentException('Only views, dist, and assets Twig/CSS/JS files can be edited.');
        }

        return $relative;
    }

    private function resourcesRoot(ThemeDefinition $theme): string
    {
        return rtrim(str_replace('\\', '/', $this->themesDir), '/').'/'.$theme->dirName.'/Resources';
    }

    private function toRelative(string $root, string $absolute): string
    {
        $rootN = rtrim(str_replace('\\', '/', $root), '/');
        $absN = str_replace('\\', '/', $absolute);

        return ltrim(substr($absN, strlen($rootN)), '/');
    }

    private function publishPublicCopy(ThemeDefinition $theme, string $relative, string $content): void
    {
        if (!str_starts_with($relative, 'dist/')) {
            return;
        }

        $publicPath = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/public/themes/'.$theme->dirName.'/'.$relative;
        $dir = dirname($publicPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($publicPath, $content);
    }
}
