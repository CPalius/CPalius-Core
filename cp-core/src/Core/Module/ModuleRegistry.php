<?php

declare(strict_types=1);

namespace App\Core\Module;

use Symfony\Component\HttpKernel\Bundle\BundleInterface;

/**
 * Resolves the active module list and applies the quarantine rules.
 * Dependency-free by design: it runs before the DI container exists (bundles.php stage).
 */
final class ModuleRegistry
{
    /** @var array<int, array{class: string, reason: string}> */
    private array $quarantined = [];

    public function __construct(
        private readonly string $activeModulesFile,
        private readonly string $quarantineLogFile,
        private readonly ?string $modulesDir = null,
    ) {
    }

    /**
     * Reads active_modules.php and validates every module class,
     * quarantining and dropping the broken ones.
     *
     * @return list<class-string<BundleInterface>>
     */
    public function getHealthyModuleBundles(): array
    {
        $this->quarantined = [];

        $declaredModules = $this->loadDeclaredModules();
        $healthy = [];

        foreach ($declaredModules as $moduleClass) {
            if (!\is_string($moduleClass) || $moduleClass === '') {
                $this->quarantine('(invalid entry)', 'active_modules.php contains an empty or invalid module entry.');
                continue;
            }

            $reason = $this->validate($moduleClass);

            if ($reason !== null) {
                $this->quarantine($moduleClass, $reason);
                continue;
            }

            $healthy[] = $moduleClass;
        }

        if ($this->quarantined !== []) {
            $this->flushQuarantineLog();
        }

        return $healthy;
    }

    /**
     * @return list<array{class: string, reason: string}>
     */
    public function getQuarantinedModules(): array
    {
        return $this->quarantined;
    }

    /**
     * Records a permanent quarantine entry for a module that was never written to
     * active_modules.php, e.g. one whose activation dry-run failed.
     */
    public function quarantinePermanently(string $moduleClass, string $reason): void
    {
        $dir = \dirname($this->quarantineLogFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s was quarantined after failing its activation pre-flight check. Reason: %s',
            date('Y-m-d H:i:s'),
            $moduleClass,
            $reason,
        );

        @file_put_contents($this->quarantineLogFile, $line.\PHP_EOL, \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Scans every module directory (active or not) and reports its status.
     * Diagnostic only: unlike getHealthyModuleBundles(), nothing is quarantined here.
     *
     * @return list<array{
     *     dirName: string,
     *     name: string,
     *     version: string,
     *     description: string,
     *     author: string,
     *     class: ?string,
     *     status: 'active'|'inactive'|'quarantined',
     *     reason: ?string,
     * }>
     */
    public function discoverAllModules(): array
    {
        if ($this->modulesDir === null || !is_dir($this->modulesDir)) {
            return [];
        }

        $declaredModules = $this->loadDeclaredModules();
        $modules = [];

        foreach (scandir($this->modulesDir) ?: [] as $dirName) {
            if ($dirName === '.' || $dirName === '..') {
                continue;
            }

            $moduleDir = $this->modulesDir.'/'.$dirName;

            if (!is_dir($moduleDir)) {
                continue;
            }

            $modules[] = $this->describeModule($dirName, $moduleDir, $declaredModules);
        }

        return $modules;
    }

    /**
     * @param list<mixed> $declaredModules
     *
     * @return array{dirName: string, name: string, version: string, description: string, author: string, class: ?string, status: 'active'|'inactive'|'quarantined', reason: ?string}
     */
    private function describeModule(string $dirName, string $moduleDir, array $declaredModules): array
    {
        $manifest = ModuleManifest::fromDirectory($moduleDir);
        $name = $manifest?->name ?? $dirName;
        $version = $manifest?->version ?? 'unknown';
        $description = $manifest?->description ?? '';
        $author = $manifest?->author ?? '';
        $moduleClass = $manifest?->bundle;

        $base = [
            'dirName' => $dirName,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'author' => $author,
            'class' => $moduleClass,
        ];

        if ($moduleClass === null) {
            return $base + [
                'status' => 'quarantined',
                'reason' => 'module.json is missing a valid "bundle" field.',
            ];
        }

        $isDeclared = \in_array($moduleClass, $declaredModules, true);
        $validationError = $this->validate($moduleClass);

        if ($validationError !== null) {
            return $base + [
                'status' => 'quarantined',
                'reason' => $validationError,
            ];
        }

        return $base + [
            'status' => $isDeclared ? 'active' : 'inactive',
            'reason' => null,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function loadDeclaredModules(): array
    {
        if (!is_file($this->activeModulesFile)) {
            return [];
        }

        try {
            $modules = require $this->activeModulesFile;
        } catch (\Throwable $e) {
            // Even active_modules.php itself may be broken; the module system then
            // stays disabled while the core still boots.
            $this->quarantine('(active_modules.php)', 'File could not be read: '.$e->getMessage());

            return [];
        }

        return \is_array($modules) ? array_values($modules) : [];
    }

    /**
     * Validates a module class. Returns null when healthy, otherwise the reason.
     */
    private function validate(string $moduleClass): ?string
    {
        // Checked ahead of class_exists() on purpose. A production install runs
        // composer install --optimize-autoloader, which bakes every PSR-4 class
        // into a static classmap array; that array is generated once and never
        // re-checked against disk. If a module's directory is deleted by hand
        // (FTP, "just remove the files") without regenerating the autoloader,
        // class_exists() still finds the class in the stale classmap and
        // reports true — then the very next include of that classmapped path
        // fatals with "failed to open stream", uncaught, anywhere the class is
        // used (Twig namespace registration, an event listener, a direct
        // `new`). Checking the file directly is the only way to catch this
        // before it becomes a runtime crash instead of a quarantine entry.
        if ($this->modulesDir !== null) {
            $relativePath = $this->classToRelativeFilePath($moduleClass);
            if ($relativePath !== null && !is_file($this->modulesDir.'/'.$relativePath)) {
                return 'Module file missing on disk (deleted without deactivating first).';
            }
        }

        // class_exists() triggers the autoloader, so a syntax error surfaces here.
        try {
            $exists = class_exists($moduleClass);
        } catch (\Throwable $e) {
            return sprintf('Class could not be loaded: %s', $e->getMessage());
        }

        if (!$exists) {
            return 'Class not found (missing file, or namespace and filename do not match).';
        }

        // Contract check: every module must also be a Symfony bundle.
        try {
            $implementsBundle = is_subclass_of($moduleClass, BundleInterface::class)
                || \in_array(BundleInterface::class, class_implements($moduleClass) ?: [], true);
        } catch (\Throwable $e) {
            return sprintf('Class could not be inspected: %s', $e->getMessage());
        }

        if (!$implementsBundle) {
            return sprintf('%s does not implement BundleInterface.', $moduleClass);
        }

        return null;
    }

    /**
     * PSR-4-resolves a `Modules\...` class name to its path relative to
     * modulesDir, the same way Composer's autoloader would — but by deriving
     * it, not by trusting a generated classmap. Returns null for a class
     * outside the `Modules\` root (nothing under this registry's purview).
     */
    private function classToRelativeFilePath(string $moduleClass): ?string
    {
        $prefix = 'Modules\\';
        if (!str_starts_with($moduleClass, $prefix)) {
            return null;
        }

        return str_replace('\\', '/', substr($moduleClass, \strlen($prefix))).'.php';
    }

    private function quarantine(string $moduleClass, string $reason): void
    {
        $this->quarantined[] = [
            'class' => $moduleClass,
            'reason' => $reason,
        ];
    }

    private function flushQuarantineLog(): void
    {
        $dir = \dirname($this->quarantineLogFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lines = [];

        foreach ($this->quarantined as $entry) {
            $lines[] = sprintf(
                '[%s] %s was quarantined. Reason: %s',
                date('Y-m-d H:i:s'),
                $entry['class'],
                $entry['reason'],
            );
        }

        @file_put_contents(
            $this->quarantineLogFile,
            implode(\PHP_EOL, $lines).\PHP_EOL,
            \FILE_APPEND | \LOCK_EX,
        );
    }
}
