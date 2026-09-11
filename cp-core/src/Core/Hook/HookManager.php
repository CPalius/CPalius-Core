<?php

declare(strict_types=1);

namespace App\Core\Hook;

use App\Core\Module\ModuleRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Isolated hook runner: flat-file Closures plus lazy #[CpHook] services in one trigger().
 * Each invocation is try/caught (Law 2.1/2.3); failures go to module_quarantine.log, never HTTP 500.
 */
final class HookManager implements HookDispatcherInterface
{
    /**
     * @param iterable<int, array{hookPoint: string, priority: int, serviceId: string, method: string}> $attributeHooks compile-time #[CpHook] defs
     * @param ContainerInterface                                                                        $serviceLocator lazy locator for #[CpHook] services
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
     * Deduped flat-file + attribute hook points for the AACP Hook Explorer.
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
                'detail' => sprintf('%s::%s() (priority: %d)', $hook['serviceId'], $hook['method'], $hook['priority']),
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
            } catch (\Throwable $e) {
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
            } catch (\Throwable $e) {
                $this->quarantineHookFailure($hook['serviceId'], $hookPoint, $hook['serviceId'].'::'.$hook['method'].'()', $e);
            }
        }

        return $context;
    }

    /**
     * Include the hook file in a static Closure with only $context — no $this and no use (...).
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
        } catch (\Throwable) {
            return null;
        }

        $safeHookPoint = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $hookPoint) ?? '';

        if ($safeHookPoint === '' || $safeHookPoint !== $hookPoint) {
            // Reject unexpected hook-point characters (including path traversal).
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
            } catch (\Throwable) {
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
                    // Skip class/interface/trait files; they share Hooks/ with #[CpHook] services.
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
     * Detect class/interface/trait/enum by tokenizing only — never include the file.
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

    private function quarantineHookFailure(string $source, string $hookPoint, string $detail, \Throwable $e): void
    {
        $this->logger->warning('Hook failed during execution and was silently skipped.', [
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
            '[%s] Hook point "%s": %s skipped at runtime because it failed. Reason: %s',
            date('Y-m-d H:i:s'),
            $hookPoint,
            $detail,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
