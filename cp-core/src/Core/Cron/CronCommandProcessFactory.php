<?php

declare(strict_types=1);

namespace App\Core\Cron;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Bir CronJob'un command_name/command_arguments alanlarından izole bir
 * "php bin/console <komut>" alt-process'i kuran tek yer. Hem otomatik
 * dispatcher (RunDueCronJobsCommand) hem de AACP'nin manuel "Şimdi
 * Çalıştır" ucu (AACPCronController::runNow()) AYNI süreç kurulumunu
 * kullanır — iki yerde ayrı ayrı Process inşa etmek (PHP binary bulma,
 * timeout, working directory) kopyala-yapıştır olurdu.
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
