<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Performance\Metrics\RouteStats;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The same table as AACP → Performance → Page costs, for the terminal. */
#[AsCommand(name: 'cp:perf:routes', description: 'Show per-route time, queries and memory collected from live traffic.')]
final class PerfRoutesCommand extends Command
{
    public function __construct(private readonly RouteStats $stats)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Forget the collected measurements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reset')) {
            $this->stats->reset();
            $io->success('Measurements reset.');

            return Command::SUCCESS;
        }

        $rows = $this->stats->report();
        if ($rows === []) {
            $io->note('No measurements yet. Browse the site; sampled requests fill this in.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Route', 'Who', 'n', 'avg ms', 'p95 ms', 'max ms', 'avg q', 'avg db ms', 'peak MB'],
            array_map(static fn (array $r): array => [
                $r['route'], $r['audience'], $r['n'], round($r['avgMs'], 1), '≤'.$r['p95Ms'], round($r['msMax']),
                round($r['avgQ'], 1), round($r['avgDbMs'], 1), round($r['mem'] / 1048576),
            ], $rows),
        );

        return Command::SUCCESS;
    }
}
