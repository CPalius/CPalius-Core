<?php

declare(strict_types=1);

namespace App\Core\Module;

use App\Core\Cache\CacheRebuildManager;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Activates a module behind a dependency check and a dry-run lint (Manifesto Law 2.2).
 * Both cp:module:activate and the AACP web UI go through this single service.
 */
final class ModuleActivator
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ActiveModulesFileWriter $fileWriter,
        private readonly ModuleDependencyChecker $dependencyChecker,
        private readonly ModuleLifecycleManager $lifecycleManager,
        private readonly CacheRebuildManager $cacheRebuildManager,
        private readonly string $projectDir,
        private readonly string $modulesDir,
    ) {
    }

    /**
     * @return array{success: bool, message: string, output: ?string, problems?: list<string>}
     */
    public function activate(string $moduleNameOrDir): array
    {
        $target = $this->findModule($moduleNameOrDir);

        if ($target === null) {
            return ['success' => false, 'message' => sprintf('No module named "%s" was found.', $moduleNameOrDir), 'output' => null];
        }

        if ($target['status'] === 'quarantined') {
            return [
                'success' => false,
                'message' => sprintf('"%s" is unhealthy and cannot be activated. Reason: %s', $target['name'], $target['reason'] ?? 'unknown'),
                'output' => null,
            ];
        }

        if ($target['status'] === 'active') {
            return ['success' => true, 'message' => sprintf('"%s" is already active.', $target['name']), 'output' => null];
        }

        $manifest = $this->manifestFor($target['dirName']);
        $moduleDir = $this->modulesDir.'/'.$target['dirName'];
        $contractProblems = ModulePackageContract::problems($moduleDir);

        if ($contractProblems !== []) {
            return [
                'success' => false,
                'message' => sprintf('"%s" cannot be activated: module contract is not satisfied.', $target['name']),
                'output' => implode(\PHP_EOL, $contractProblems),
                'problems' => $contractProblems,
            ];
        }

        // The dependency matrix is checked BEFORE the dry-run: a missing requirement
        // is a contract error, not a compile error, and must not quarantine the module.
        $problems = $this->dependencyChecker->checkActivation($manifest);

        if ($problems !== []) {
            return [
                'success' => false,
                'message' => sprintf('"%s" cannot be activated: unmet dependencies.', $target['name']),
                'output' => implode(\PHP_EOL, $problems),
                'problems' => $problems,
            ];
        }

        /** @var class-string $moduleClass */
        $moduleClass = $target['class'];
        $dryRunResult = $this->runDryRun($moduleClass);

        if (!$dryRunResult['success']) {
            $this->moduleRegistry->quarantinePermanently($moduleClass, $this->summarize($dryRunResult['output']));

            return [
                'success' => false,
                'message' => sprintf('"%s" failed compile-time validation and was quarantined.', $target['name']),
                'output' => $dryRunResult['output'],
            ];
        }

        $this->fileWriter->add($moduleClass);
        $this->clearCache();

        $lifecycle = $this->lifecycleManager->onActivated($manifest);

        if ($lifecycle['message'] !== null) {
            return [
                'success' => true,
                'message' => sprintf('"%s" was activated, but its installer reported: %s', $target['name'], $lifecycle['message']),
                'output' => null,
            ];
        }

        return [
            'success' => true,
            'message' => match ($lifecycle['ran']) {
                'install' => sprintf('"%s" was activated and installed.', $target['name']),
                'upgrade' => sprintf('"%s" was activated and upgraded to %s.', $target['name'], $manifest->version),
                default => sprintf('"%s" was activated.', $target['name']),
            },
            'output' => null,
        ];
    }

    /**
     * Removes a module from the active list, optionally running its uninstall hook.
     *
     * @return array{success: bool, message: string, output: ?string, problems?: list<string>}
     */
    public function deactivate(string $moduleNameOrDir, bool $purgeData = false): array
    {
        $target = $this->findModule($moduleNameOrDir);

        if ($target === null || $target['class'] === null) {
            return ['success' => false, 'message' => sprintf('No module named "%s" was found.', $moduleNameOrDir), 'output' => null];
        }

        if ($target['status'] !== 'active') {
            return ['success' => true, 'message' => sprintf('"%s" is not active.', $target['name']), 'output' => null];
        }

        $manifest = $this->manifestFor($target['dirName']);
        $dependents = $this->dependencyChecker->checkDeactivation($manifest);

        if ($dependents !== []) {
            return [
                'success' => false,
                'message' => sprintf('"%s" cannot be deactivated: other active modules depend on it.', $target['name']),
                'output' => implode(\PHP_EOL, $dependents),
                'problems' => $dependents,
            ];
        }

        if ($purgeData) {
            $uninstall = $this->lifecycleManager->onUninstalled($manifest);

            if ($uninstall['message'] !== null) {
                return [
                    'success' => false,
                    'message' => sprintf('"%s" uninstall hook failed; nothing was deactivated.', $target['name']),
                    'output' => $uninstall['message'],
                ];
            }
        }

        $this->fileWriter->remove($target['class']);
        $this->clearCache();

        return [
            'success' => true,
            'message' => $purgeData
                ? sprintf('"%s" was deactivated and its data removed.', $target['name'])
                : sprintf('"%s" was deactivated. Its data was kept.', $target['name']),
            'output' => null,
        ];
    }

    /**
     * @return array{dirName: string, name: string, version: string, class: ?string, status: string, reason: ?string}|null
     */
    private function findModule(string $moduleNameOrDir): ?array
    {
        foreach ($this->moduleRegistry->discoverAllModules() as $module) {
            if (strcasecmp($module['dirName'], $moduleNameOrDir) === 0 || strcasecmp($module['name'], $moduleNameOrDir) === 0) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Falls back to a synthetic manifest so a module without module.json still works.
     */
    private function manifestFor(string $dirName): ModuleManifest
    {
        return ModuleManifest::fromDirectory($this->modulesDir.'/'.$dirName)
            ?? new ModuleManifest(dirName: $dirName, name: $dirName, version: '0.0.0', bundle: null);
    }

    /**
     * @return array{success: bool, output: string}
     */
    private function runDryRun(string $moduleClass): array
    {
        $originalModules = $this->fileWriter->read();

        $this->fileWriter->add($moduleClass);

        try {
            $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
            $consolePath = $this->projectDir.'/cp-core/bin/console';

            $clearResult = $this->runLint([$phpBinary, $consolePath, 'cache:clear', '--no-warmup']);

            if (!$clearResult['success']) {
                return $clearResult;
            }

            // --parse-tags is not optional here: core's own services.yaml uses
            // !tagged_iterator, so without it this lint reports the core as
            // broken and quarantines every module anyone tries to activate —
            // the failure is in the checker, and it names the module.
            $yamlResult = $this->runLint([$phpBinary, $consolePath, 'lint:yaml', '--parse-tags', 'cp-core/config', 'cp-content/modules']);

            if (!$yamlResult['success']) {
                return $yamlResult;
            }

            return $this->runLint([$phpBinary, $consolePath, 'lint:container']);
        } finally {
            $this->fileWriter->replaceAll($originalModules);
        }
    }

    /**
     * Lint in a throwaway cache dir so `cache:clear --no-warmup` never deletes
     * the live container a request is still requiring (the ContainerXxx/getY.php miss).
     *
     * @param list<string> $commandLine
     *
     * @return array{success: bool, output: string}
     */
    private function runLint(array $commandLine): array
    {
        $process = new Process($commandLine, $this->projectDir);
        $process->setTimeout(120);
        $env = getenv();
        $process->setEnv(array_merge(\is_array($env) ? $env : [], [
            'CPALIUS_CACHE_DIR' => $this->lintCacheDir(),
        ]));
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => trim($process->getOutput().\PHP_EOL.$process->getErrorOutput()),
        ];
    }

    private function lintCacheDir(): string
    {
        return $this->projectDir.'/cp-core/var/cache/module-lint';
    }

    private function summarize(string $output): string
    {
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $output) ?? $output;
        $firstLine = trim(strtok($plain, "\n") ?: $plain);

        if ($firstLine === '') {
            foreach (explode("\n", $plain) as $line) {
                if (trim($line) !== '') {
                    $firstLine = trim($line);
                    break;
                }
            }
        }

        return mb_strimwidth($firstLine, 0, 200, '...');
    }

    /**
     * Used to hand-roll its own synchronous `cache:clear --no-warmup` subprocess,
     * deliberately leaving the container unbuilt so "the next real request warms
     * it lazily". That next request is never a random visitor — activate/deactivate/
     * delete all redirect straight back into AACP, so it was always this same admin's
     * own next click that was guaranteed to hit the gap and pay for an inline compile
     * squeezed into one HTTP request's time/memory budget (the exact failure mode
     * CacheRebuildManager exists to avoid — see cache-purge-never-forced-2.2.16).
     * Routing through it here closes that gap the same way every other cache-clearing
     * call site in the app already does: defer the purge past this response, rebuild
     * in the background.
     */
    private function clearCache(): void
    {
        $this->cacheRebuildManager->clearSymfonyCache();
    }
}
