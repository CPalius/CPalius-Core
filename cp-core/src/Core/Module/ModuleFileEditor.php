<?php

declare(strict_types=1);

namespace App\Core\Module;

use App\Core\Theme\ThemeSourceLinter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes the source files of one installed module for the AACP module editor.
 * Paths are confined to cp-content/modules/<Module>/, every save is syntax checked and the previous
 * content is kept under cp-core/var/module-editor so a bad edit can be undone by hand.
 */
final class ModuleFileEditor
{
    private const MAX_BYTES = 524288;
    private const KINDS = [
        'php' => 'php', 'twig' => 'twig', 'css' => 'css', 'js' => 'js',
        'yaml' => 'yaml', 'yml' => 'yaml', 'json' => 'json', 'md' => 'text', 'txt' => 'text', 'sql' => 'text',
    ];

    public function __construct(
        private readonly ThemeSourceLinter $linter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<array{path: string, kind: string, label: string}>
     */
    public function listFiles(string $module): array
    {
        $root = $this->root($module);
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), \strlen(str_replace('\\', '/', $root))), '/');
            if (!$file->isFile() || $file->isLink() || $this->kindFromRelative($relative) === null || str_starts_with($relative, 'vendor/')) {
                continue;
            }
            $files[] = ['path' => $relative, 'kind' => (string) $this->kindFromRelative($relative), 'label' => $relative];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    /**
     * @return array{path: string, kind: string, content: string, bytes: int}
     */
    public function read(string $module, string $relative): array
    {
        $absolute = $this->absolutePath($module, $relative);
        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new \RuntimeException('The module file could not be read.');
        }

        return ['path' => $relative, 'kind' => (string) $this->kindFromRelative($relative), 'content' => $content, 'bytes' => \strlen($content)];
    }

    /**
     * @return list<string> problems; empty means the source parses
     */
    public function lint(string $kind, string $content, string $label): array
    {
        return match ($kind) {
            'php' => $this->phpProblems($content),
            'json' => json_validate($content) ? [] : ['Invalid JSON: '.json_last_error_msg()],
            'yaml' => $this->yamlProblems($content),
            default => $this->linter->problems($kind, $content, $label),
        };
    }

    /**
     * PHP and module.json can never be saved broken: a parse error in a module class would take the whole site down on the next request.
     *
     * @return list<string> problems that stopped the save (or that were overridden with $force)
     */
    public function write(string $module, string $relative, string $content, bool $force): array
    {
        $absolute = $this->absolutePath($module, $relative);
        $kind = (string) $this->kindFromRelative($relative);

        if (\strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Files larger than 512 KiB cannot be saved from AACP.');
        }

        $problems = $this->lint($kind, $content, $relative);
        $strict = $kind === 'php' || basename($relative) === 'module.json';
        if ($problems !== [] && $strict) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        if ($problems !== [] && !$force) {
            return $problems;
        }

        $this->backup($module, $relative, $absolute);

        $tmp = $absolute.'.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('The module file could not be written.');
        }
        if (!@rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new \RuntimeException('The module file could not be replaced.');
        }
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($absolute, true);
        }

        return $problems;
    }

    public function kindFromRelative(string $relative): ?string
    {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        $name = str_ends_with($relative, '.html.twig') ? 'twig' : strtolower(pathinfo($relative, \PATHINFO_EXTENSION));

        return self::KINDS[$name] ?? null;
    }

    public function absolutePath(string $module, string $relative): string
    {
        $relative = str_replace('\\', '/', ltrim(trim($relative), '/'));
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")
            || preg_match('#^[A-Za-z0-9_][A-Za-z0-9._/-]*$#', $relative) !== 1 || $this->kindFromRelative($relative) === null) {
            throw new \InvalidArgumentException('That path is not allowed.');
        }

        $root = realpath($this->root($module));
        if ($root === false) {
            throw new \InvalidArgumentException('The module directory is missing.');
        }

        $candidate = $root.'/'.$relative;
        $real = realpath($candidate);
        $check = $real !== false ? $real : realpath(\dirname($candidate));
        if ($check === false || !str_starts_with(str_replace('\\', '/', $check).'/', str_replace('\\', '/', $root).'/')) {
            throw new \InvalidArgumentException('That path is outside the module.');
        }

        return $real !== false ? $real : $candidate;
    }

    private function root(string $module): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $module) !== 1) {
            throw new \InvalidArgumentException('Unknown module.');
        }

        return rtrim(str_replace('\\', '/', $this->projectDir), '/').'/cp-content/modules/'.$module;
    }

    /**
     * @return list<string>
     */
    private function phpProblems(string $content): array
    {
        try {
            token_get_all($content, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            return [\sprintf('PHP syntax error on line %d: %s', $e->getLine(), $e->getMessage())];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function yamlProblems(string $content): array
    {
        try {
            Yaml::parse($content);
        } catch (ParseException $e) {
            return ['Invalid YAML: '.$e->getMessage()];
        }

        return [];
    }

    private function backup(string $module, string $relative, string $absolute): void
    {
        if (!is_file($absolute)) {
            return;
        }

        $dir = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/cp-core/var/module-editor/'.$module.'/'.\dirname($relative);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        @copy($absolute, $dir.'/'.basename($relative).'.'.date('Ymd-His').'.bak');
    }
}
