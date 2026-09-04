<?php

declare(strict_types=1);

namespace App\Core\Cron;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Builds an isolated "php bin/console <command>" Process for dispatcher and AACP "run now".
 */
final class CronCommandProcessFactory
{
    private const TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function create(string $commandName, ?string $commandArguments): Process
    {
        $phpBinary = (new PhpExecutableFinder())->find();
        $consolePath = $this->projectDir.'/cp-core/bin/console';

        $commandLine = [$phpBinary !== false ? $phpBinary : 'php', $consolePath, $commandName];

        if ($commandArguments !== null && trim($commandArguments) !== '') {
            foreach (preg_split('/\s+/', trim($commandArguments)) as $argument) {
                $commandLine[] = $argument;
            }
        }

        $process = new Process($commandLine, $this->projectDir.'/cp-core');
        $process->setTimeout(self::TIMEOUT_SECONDS);

        return $process;
    }
}
