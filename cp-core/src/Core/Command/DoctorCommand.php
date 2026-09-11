<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Diagnostics\Doctor;
use App\Core\Diagnostics\DoctorFinding;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One command that answers "is this installation actually healthy?".
 *
 * The failure this is built to prevent is the quiet one. Five migrations once
 * sat unapplied for several sessions without a single visible symptom, because
 * the features that needed them were written to degrade silently. Every check
 * here follows the same principle: it looks for the problems that do not
 * announce themselves.
 *
 * --fail-on makes it a deployment gate rather than a report, mirroring
 * cp:security:audit so the two feel like one tool.
 */
#[AsCommand(
    name: 'cp:doctor',
    description: 'Diagnoses the installation: migrations, modules, capabilities, translations, environment and security posture.',
)]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly Doctor $doctor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit non-zero when a finding of this severity or worse exists (critical|high|medium|low)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated check keys to run (see --list)')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available check keys and exit')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include checks that passed')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $io->listing($this->doctor->availableKeys());

            return Command::SUCCESS;
        }

        $failOn = $input->getOption('fail-on');
        $failOn = \is_string($failOn) ? strtolower(trim($failOn)) : '';

        // A mistyped threshold must not silently disable the gate — that would
        // turn a CI safety net into decoration.
        if ($failOn !== '' && !\in_array($failOn, [
            DoctorFinding::SEVERITY_CRITICAL,
            DoctorFinding::SEVERITY_HIGH,
            DoctorFinding::SEVERITY_MEDIUM,
            DoctorFinding::SEVERITY_LOW,
        ], true)) {
            $io->error(sprintf('Unknown --fail-on value "%s". Use critical, high, medium or low.', $failOn));

            return Command::INVALID;
        }

        $only = $this->parseOnly($input->getOption('only'));
        $unknown = array_diff($only, $this->doctor->availableKeys());

        if ($unknown !== []) {
            $io->error(sprintf('Unknown check key(s): %s. Use --list to see them.', implode(', ', $unknown)));

            return Command::INVALID;
        }

        $findings = $this->doctor->run($only);
        $summary = $this->doctor->summary($findings);
        $showPasses = (bool) $input->getOption('all');

        if ($input->getOption('json')) {
            $output->writeln($this->toJson($findings, $summary, $showPasses));

            return $this->exitCode($findings, $failOn);
        }

        $this->render($io, $findings, $summary, $showPasses);

        return $this->exitCode($findings, $failOn);
    }

    /**
     * @param list<DoctorFinding> $findings
     * @param array<string, int>  $summary
     */
    private function render(SymfonyStyle $io, array $findings, array $summary, bool $showPasses): void
    {
        $io->title('CPalius doctor');

        $rows = [];
        foreach ($findings as $finding) {
            if ($finding->isPass() && !$showPasses) {
                continue;
            }

            $rows[] = [
                strtoupper($finding->severity),
                $finding->id,
                $finding->title,
                wordwrap($finding->detail, 60, \PHP_EOL, true),
                $finding->remedy === null ? '' : wordwrap($finding->remedy, 40, \PHP_EOL, true),
            ];
        }

        if ($rows !== []) {
            $io->table(['Severity', 'ID', 'Check', 'Detail', 'Remedy'], $rows);
        }

        $line = sprintf(
            '%d critical · %d high · %d medium · %d low · %d passed',
            $summary[DoctorFinding::SEVERITY_CRITICAL] ?? 0,
            $summary[DoctorFinding::SEVERITY_HIGH] ?? 0,
            $summary[DoctorFinding::SEVERITY_MEDIUM] ?? 0,
            $summary[DoctorFinding::SEVERITY_LOW] ?? 0,
            $summary[DoctorFinding::SEVERITY_PASS] ?? 0,
        );

        if ($this->doctor->isHealthy($findings)) {
            $io->success('No problems found. '.$line);

            return;
        }

        if (($summary[DoctorFinding::SEVERITY_CRITICAL] ?? 0) > 0) {
            $io->error($line);

            return;
        }

        $io->warning($line);
    }

    /**
     * @param list<DoctorFinding> $findings
     * @param array<string, int>  $summary
     */
    private function toJson(array $findings, array $summary, bool $showPasses): string
    {
        $payload = [
            'healthy' => $this->doctor->isHealthy($findings),
            'summary' => $summary,
            'findings' => array_values(array_map(
                static fn (DoctorFinding $finding): array => $finding->toArray(),
                array_filter(
                    $findings,
                    static fn (DoctorFinding $finding): bool => $showPasses || !$finding->isPass(),
                ),
            )),
        ];

        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function parseOnly(mixed $option): array
    {
        if (!\is_string($option) || trim($option) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $key): string => trim($key),
            explode(',', $option),
        ), static fn (string $key): bool => $key !== ''));
    }

    /**
     * @param list<DoctorFinding> $findings
     */
    private function exitCode(array $findings, string $failOn): int
    {
        if ($failOn === '') {
            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            if ($finding->isAtLeast($failOn)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
