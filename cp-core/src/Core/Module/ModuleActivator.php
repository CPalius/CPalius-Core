<?php

namespace App\Core\Module;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Bir modülü, dry-run/lint doğrulaması yaparak güvenli şekilde aktive eder
 * (Manifesto Law 2.2: Compile-Time Protection). Hem cp:module:activate
 * komutu hem de AACP'nin web arayüzü (AACPController::activateModule)
 * AYNI bu servisi kullanır — aksi halde web'den bozuk bir modül aktive
 * edilip container derlemesi çökertilebilirdi.
 */
final class ModuleActivator
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ActiveModulesFileWriter $fileWriter,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{success: bool, message: string, output: ?string}
     */
    public function activate(string $moduleNameOrDir): array
    {
        $modules = $this->moduleRegistry->discoverAllModules();
        $target = null;
        foreach ($modules as $module) {
            if (strcasecmp($module['dirName'], $moduleNameOrDir) === 0 || strcasecmp($module['name'], $moduleNameOrDir) === 0) {
                $target = $module;
                break;
            }
        }

        if ($target === null) {
            return ['success' => false, 'message' => sprintf('"%s" adında bir modül bulunamadı.', $moduleNameOrDir), 'output' => null];
        }

        if ($target['status'] === 'quarantined') {
            return [
                'success' => false,
                'message' => sprintf('"%s" modülü sağlıksız olduğu için aktive edilemiyor. Sebep: %s', $target['name'], $target['reason'] ?? 'bilinmiyor'),
                'output' => null,
            ];
        }

        if ($target['status'] === 'active') {
            return ['success' => true, 'message' => sprintf('"%s" modülü zaten aktif.', $target['name']), 'output' => null];
        }

        /** @var class-string $moduleClass */
        $moduleClass = $target['class'];

        $dryRunResult = $this->runDryRun($moduleClass);

        if (!$dryRunResult['success']) {
            $this->moduleRegistry->quarantinePermanently($moduleClass, $this->summarize($dryRunResult['output']));

            return [
                'success' => false,
                'message' => sprintf('"%s" modülü compile-time hatası verdiği için aktive edilemedi ve karantinaya alındı.', $target['name']),
                'output' => $dryRunResult['output'],
            ];
        }

        $this->fileWriter->add($moduleClass);
        $this->clearCache();

        return ['success' => true, 'message' => sprintf('"%s" modülü aktive edildi.', $target['name']), 'output' => null];
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

            $clearResult = $this->runIsolated([$phpBinary, $consolePath, 'cache:clear', '--no-warmup']);
            if (!$clearResult['success']) {
                return $clearResult;
            }

            $yamlResult = $this->runIsolated([$phpBinary, $consolePath, 'lint:yaml', 'cp-core/config', 'cp-content/modules']);
            if (!$yamlResult['success']) {
                return $yamlResult;
            }

            return $this->runIsolated([$phpBinary, $consolePath, 'lint:container']);
        } finally {
            $this->fileWriter->replaceAll($originalModules);
        }
    }

    /**
     * @param list<string> $commandLine
     * @return array{success: bool, output: string}
     */
    private function runIsolated(array $commandLine): array
    {
        $process = new Process($commandLine, $this->projectDir);
        $process->setTimeout(120);
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => trim($process->getOutput().PHP_EOL.$process->getErrorOutput()),
        ];
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

        return mb_strimwidth($firstLine, 0, 200, '…');
    }

    private function clearCache(): void
    {
        $phpBinary = (new PhpExecutableFinder())->find();
        $consolePath = $this->projectDir.'/cp-core/bin/console';

        $process = new Process([$phpBinary ?: 'php', $consolePath, 'cache:clear']);
        $process->setTimeout(120);
        $process->run();
    }
}
