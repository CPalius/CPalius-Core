<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Module\ModuleActivator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cp:module:activate',
    description: 'Validates a module and adds it to active_modules.php.',
)]
final class ModuleActivateCommand extends Command
{
    public function __construct(
        private readonly ModuleActivator $moduleActivator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module-name', InputArgument::REQUIRED, 'Directory name of the module, e.g. Blog');
    }

    /**
     * The dependency matrix and dry-run live in ModuleActivator, so the CLI and the
     * AACP web UI share the exact same guarantees (Manifesto Law 2.2).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moduleName = (string) $input->getArgument('module-name');

        $io->section(sprintf('Running pre-flight checks for "%s"...', $moduleName));

        $result = $this->moduleActivator->activate($moduleName);

        if (!$result['success']) {
            $io->error($result['message']);

            if (($result['problems'] ?? []) !== []) {
                $io->listing($result['problems']);
            } elseif ($result['output'] !== null) {
                $io->block($result['output'], 'ERROR OUTPUT', 'fg=red', ' ', true);
            }

            return Command::FAILURE;
        }

        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
