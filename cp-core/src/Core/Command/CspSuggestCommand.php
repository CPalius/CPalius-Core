<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Security\Http\CspViolationAdvisor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cp:security:csp-suggest', description: 'Summarise CSP violation reports and say which blocked sources are worth allowing.')]
final class CspSuggestCommand extends Command
{
    public function __construct(private readonly CspViolationAdvisor $advisor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of reports to read', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->advisor->advise((int) $input->getOption('days'));

        if ($rows === []) {
            $io->success('No CSP violations reported in that period.');

            return Command::SUCCESS;
        }

        $io->table(['directive', 'blocked', 'reports', 'pages', 'what to do'], array_map(
            static fn (array $r): array => [$r['directive'], $r['blocked'], $r['reports'], $r['pages'], $r['advice']],
            $rows,
        ));
        $io->note('Reports come from visitor browsers and can be forged or caused by browser extensions: allow a source only if you recognise it. Nothing is changed by this command.');

        return Command::SUCCESS;
    }
}
