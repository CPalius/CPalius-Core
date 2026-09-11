<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Cron\Attribute\CpCronJob;
use Symfony\Component\Process\Process;

/**
 * Shared-hosting drain for the Doctrine Messenger transport.
 *
 * Long-lived `messenger:consume async` (supervisor) is preferred on VPS installs;
 * this cron ticker keeps mail/notification delivery moving when only a
 * minute-level cron is available. Runs in an isolated subprocess so a stuck
 * SMTP call cannot poison the cron runner.
 */
final class MessengerConsumeTask
{
    private const MESSAGE_LIMIT = 25;
    private const TIME_LIMIT_SECONDS = 45;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
    }

    #[CpCronJob(
        schedule: '* * * * *',
        name: 'cpalius.messenger.consume',
        description: 'Drain Doctrine Messenger async transport (mail and notification delivery)',
    )]
    public function execute(): string
    {
        $console = $this->projectDir.'/cp-core/bin/console';
        if (!is_file($console)) {
            return 'skipped=no_console';
        }

        $process = new Process([
            $this->phpBinary(),
            $console,
            'messenger:consume',
            'async',
            '--limit='.self::MESSAGE_LIMIT,
            '--time-limit='.self::TIME_LIMIT_SECONDS,
            '--no-interaction',
            '--env='.$this->environment,
        ], $this->projectDir);
        $process->setTimeout(self::TIME_LIMIT_SECONDS + 30);
        $process->run();

        $exit = $process->getExitCode() ?? 1;
        $out = trim($process->getOutput().' '.$process->getErrorOutput());

        return sprintf(
            'exit=%d limit=%d %s',
            $exit,
            self::MESSAGE_LIMIT,
            $out !== '' ? mb_substr($out, 0, 200) : 'ok',
        );
    }

    private function phpBinary(): string
    {
        if (\defined('PHP_BINARY') && \is_string(PHP_BINARY) && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        return 'php';
    }
}
