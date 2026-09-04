<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Module\ModuleActivator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cp:module:deactivate',
    description: 'Deactivates a module, optionally running its uninstall hook.',
)]
final class ModuleDeactivateCommand extends Command
{
    public function __construct(
        private readonly ModuleActivator $moduleActivator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('module-name', InputArgument::REQUIRED, 'Directory name of the module, e.g. Blog')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Also run the module installer\'s uninstall() hook and drop its data');
    }

    /**
     * Dependency checks and lifecycle hooks live in ModuleActivator, so the CLI and
     * the AACP web UI behave identically.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moduleName = (string) $input->getArgument('module-name');
        $purge = (bool) $input->getOption('purge');

        if ($purge && !$io->confirm(sprintf('This permanently removes "%s" data. Continue?', $moduleName), false)) {
            $io->note('Aborted.');

            return Command::SUCCESS;
        }

        $result = $this->moduleActivator->deactivate($moduleName, $purge);

        if (!$result['success']) {
            $io->error($result['message']);

            if ($result['output'] !== null) {
                $io->block($result['output'], 'DETAILS', 'fg=red', ' ', true);
            }

            return Command::FAILURE;
        }

        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
