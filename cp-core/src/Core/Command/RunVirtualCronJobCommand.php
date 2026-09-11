<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Cron\CronManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * OS entry point for code-based virtual cron tasks (Attribute or flat-file); bridges to CronManager::runVirtualTask().
 * Runs in an isolated subprocess so a failing task cannot crash the dispatcher (Manifesto Law 2.1).
 */
#[AsCommand(
    name: 'cp:cron:run-virtual',
    description: 'Runs a code-based (Attribute/Flat-File) virtual cron job by jobName.',
)]
final class RunVirtualCronJobCommand extends Command
{
    public function __construct(
        private readonly CronManager $cronManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobName', InputArgument::REQUIRED, 'Virtual job name matched by CronManager::findDefinitionByJobName() (e.g. "blog.publish_scheduled").');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobName = (string) $input->getArgument('jobName');

        try {
            $result = $this->cronManager->runVirtualTask($jobName);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($result !== '') {
            $output->writeln($result);
        }

        $io->success(sprintf('Virtual cron job "%s" executed.', $jobName));

        return Command::SUCCESS;
    }
}
