<?php

declare(strict_types=1);

namespace App\Core\Config\Command;

use App\Core\Config\ConfigManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Config sync between the database and cp-content/config/sync/.
 *
 *   cp:config export                 write live config to YAML
 *   cp:config status                 show drift
 *   cp:config import [--only=x] [-f]  apply YAML (dry-run without -f)
 */
#[AsCommand(name: 'cp:config', description: 'Export / diff / import CPalius configuration (settings, fields)')]
final class ConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'export | status | import')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated document names (import only)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Actually apply the import (otherwise dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        return match ($action) {
            'export' => $this->export($io),
            'status' => $this->status($io),
            'import' => $this->import($io, $input),
            default => $this->invalid($io),
        };
    }

    private function export(SymfonyStyle $io): int
    {
        $written = $this->manager->export();
        $io->success(sprintf('Exported %d document(s): %s', \count($written), implode(', ', $written)));

        return Command::SUCCESS;
    }

    private function status(SymfonyStyle $io): int
    {
        $rows = [];
        $drift = false;
        foreach ($this->manager->status() as $document => $info) {
            $rows[] = [$document, $info['status'], implode("\n", $info['changes']) ?: '—'];
            $drift = $drift || $info['changes'] !== [] || $info['status'] === 'only-live';
        }
        $io->table(['Document', 'Status', 'Changes if imported'], $rows);

        return $drift ? 1 : Command::SUCCESS;
    }

    private function import(SymfonyStyle $io, InputInterface $input): int
    {
        $only = null;
        $rawOnly = (string) $input->getOption('only');
        if ($rawOnly !== '') {
            $only = array_values(array_filter(array_map('trim', explode(',', $rawOnly))));
        }

        $status = $this->manager->status();
        $pending = [];
        foreach ($status as $document => $info) {
            if ($only !== null && !\in_array($document, $only, true)) {
                continue;
            }
            if ($info['changes'] !== []) {
                $pending[$document] = $info['changes'];
            }
        }

        if ($pending === []) {
            $io->success('Nothing to import — config is in sync.');

            return Command::SUCCESS;
        }

        foreach ($pending as $document => $changes) {
            $io->section($document);
            $io->listing($changes);
        }

        if (!$input->getOption('force')) {
            $io->note('Dry run. Re-run with --force to apply.');

            return Command::SUCCESS;
        }

        $applied = $this->manager->import($only);
        $count = array_sum(array_map('count', $applied));
        $io->success(sprintf('Applied %d change(s) across %d document(s).', $count, \count($applied)));

        return Command::SUCCESS;
    }

    private function invalid(SymfonyStyle $io): int
    {
        $io->error('Unknown action. Use: export | status | import');

        return Command::INVALID;
    }
}
