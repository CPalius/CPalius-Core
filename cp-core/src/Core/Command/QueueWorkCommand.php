<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Queue\QueueWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cp:queue:work', description: 'Process due CPalius async jobs in isolation')]
final class QueueWorkCommand extends Command
{
    public function __construct(
        private readonly QueueWorker $queueWorker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max jobs this run', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $stats = $this->queueWorker->run($limit);
        $output->writeln(sprintf(
            'processed=%d retried=%d failed=%d',
            $stats['processed'],
            $stats['retried'],
            $stats['failed'],
        ));

        return Command::SUCCESS;
    }
}
