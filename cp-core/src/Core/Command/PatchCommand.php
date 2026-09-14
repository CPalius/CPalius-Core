<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Version\CpVersion;
use App\Core\Version\PatchChecker;
use App\Core\Version\PatchInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The console face of the file-level patcher.
 *
 * Shows what is pending by default and writes nothing without --apply. The
 * inversion matters on a command an operator might run while reading a
 * changelog: the safe invocation has to be the short one.
 *
 * --apply is also not enough on its own when the session is interactive; it
 * prints the file list and asks. A patch is a remote list of paths being
 * written over a running site, and the one thing that must never happen is that
 * it lands because someone tab-completed.
 */
#[AsCommand(
    name: 'cp:patch',
    description: 'Shows, and optionally applies, the pending CPalius file patch.',
)]
final class PatchCommand extends Command
{
    public function __construct(
        private readonly PatchChecker $patches,
        private readonly PatchInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Fetch the patch index before reporting')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the pending patch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln(sprintf('Running version: <info>%s</info>', CpVersion::VERSION));

        if ($input->getOption('refresh')) {
            $io->writeln($this->patches->refresh());
        }

        try {
            $manifest = $this->installer->plan();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($manifest === null) {
            $io->success('No patch is pending.');

            foreach ($this->patches->queued() as $entry) {
                $io->writeln(sprintf(
                    '  %s is published but upgrades from %s, which is not the running version.',
                    $entry['version'],
                    $entry['base'],
                ));
            }

            return Command::SUCCESS;
        }

        $io->section(sprintf('Patch %s (from %s)', $manifest->version, $manifest->base));

        if ($manifest->summary !== '') {
            $io->writeln($manifest->summary);
        }

        if ($manifest->critical) {
            $io->warning('This patch is marked critical.');
        }

        $rows = [];

        foreach ($manifest->files as $file) {
            $rows[] = [$file['action'], $file['path'], $file['sha256'] === null ? '—' : substr($file['sha256'], 0, 12).'…'];
        }

        $io->table(['Action', 'Path', 'SHA-256'], $rows);

        $blockers = $this->installer->blockers();

        if ($blockers !== []) {
            $io->error('Cannot apply: '.implode(', ', $blockers));

            return Command::FAILURE;
        }

        if (!$input->getOption('apply')) {
            $io->note('Run again with --apply to install it.');

            return Command::SUCCESS;
        }

        if ($input->isInteractive() && !$io->confirm(sprintf('Overwrite %d file(s) on this installation?', count($manifest->files)), false)) {
            $io->writeln('Aborted; nothing was changed.');

            return Command::SUCCESS;
        }

        try {
            foreach ($this->installer->apply() as $line) {
                $io->writeln($line);
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Patch %s applied.', $manifest->version));

        return Command::SUCCESS;
    }
}
