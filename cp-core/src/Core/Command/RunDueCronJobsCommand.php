<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Cron\CronDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Single OS-level entry point for CPalius unified automation; dispatch logic lives in CronDispatcher::runDueTasks().
 * One crontab/Task Scheduler entry should invoke this command (or the HTTP /cron/execute endpoint).
 */
#[AsCommand(
    name: 'cp:cron:run',
    description: 'Runs all due cron jobs from DB (cp_cron_jobs) and code-based (Attribute/Flat-File) sources.',
)]
final class RunDueCronJobsCommand extends Command
{
    public function __construct(
        private readonly CronDispatcher $cronDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $results = $this->cronDispatcher->runDueTasks();

        if ($results === []) {
            $io->comment('No active cron jobs are due to run.');

            return Command::SUCCESS;
        }

        foreach ($results as $result) {
            $prefix = $result['sourceType'] === 'code' ? '[CODE] ' : '';
            $label = $prefix.$result['jobName'];

            if ($result['success']) {
                $io->success(sprintf('%s: %s', $label, $result['message']));
            } else {
                $io->error(sprintf('%s: %s', $label, $result['message']));
            }
        }

        return Command::SUCCESS;
    }
}
