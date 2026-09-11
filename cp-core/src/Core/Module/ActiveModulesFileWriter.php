<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Rewrite config/active_modules.php safely via var_export, then ::class form for readability.
 */
final class ActiveModulesFileWriter
{
    public function __construct(
        private readonly string $activeModulesFile,
    ) {
    }

    /**
     * @return list<class-string>
     */
    public function read(): array
    {
        if (!is_file($this->activeModulesFile)) {
            return [];
        }

        $modules = require $this->activeModulesFile;

        return is_array($modules) ? array_values($modules) : [];
    }

    /**
     * @param class-string $moduleClass
     */
    public function add(string $moduleClass): void
    {
        $modules = $this->read();

        if (in_array($moduleClass, $modules, true)) {
            return;
        }

        $modules[] = $moduleClass;
        $this->write($modules);
    }

    /**
     * @param class-string $moduleClass
     */
    public function remove(string $moduleClass): void
    {
        $modules = array_values(array_filter(
            $this->read(),
            static fn (string $existing) => $existing !== $moduleClass,
        ));

        $this->write($modules);
    }

    /**
     * Replace the whole file with the given list (used by dry-run restore).
     *
     * @param list<class-string> $modules
     */
    public function replaceAll(array $modules): void
    {
        $this->write($modules);
    }

    /**
     * @param list<class-string> $modules
     */
    private function write(array $modules): void
    {
        $exported = var_export($modules, true);

        // var_export() emits array (...); rewrite to short array syntax.
        $exported = preg_replace('/^array \(/', '[', $exported);
        $exported = preg_replace('/\)$/', ']', $exported);
        $exported = preg_replace('/^(\s*)\d+ => /m', '$1', $exported);

        // Rewrite 'Modules\\Blog\\BlogModule' strings to Modules\Blog\BlogModule::class.
        $exported = preg_replace_callback(
            "/'((?:[A-Za-z0-9_]+\\\\\\\\)+[A-Za-z0-9_]+)'/",
            static fn (array $m) => str_replace('\\\\', '\\', $m[1]).'::class',
            $exported,
        );

        $contents = <<<PHP
        <?php

        // Active modules. Static file, read before container boot (bundles.php); no DB.

        return {$exported};

        PHP;

        $dir = \dirname($this->activeModulesFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Atomic write: temp file then rename so a crash never leaves a half-written file.
        $tmpFile = $this->activeModulesFile.'.'.uniqid('tmp_', true);
        file_put_contents($tmpFile, $contents, LOCK_EX);
        rename($tmpFile, $this->activeModulesFile);
    }
}
