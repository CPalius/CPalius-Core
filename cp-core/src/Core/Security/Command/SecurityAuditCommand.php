<?php

declare(strict_types=1);

namespace App\Core\Security\Command;

use App\Core\Security\Audit\SecurityAuditor;
use App\Core\Security\Audit\SecurityFinding;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs the security audit from the shell so it can gate a deployment.
 *
 * --strict exits non-zero on any finding above the chosen severity, which is what
 * makes this usable in CI rather than merely informative.
 */
#[AsCommand(
    name: 'cp:security:audit',
    description: 'Audits the running security configuration and reports a posture score.',
)]
final class SecurityAuditCommand extends Command
{
    /** Severities ordered from worst to mildest, for --fail-on comparison. */
    private const ORDER = [
        SecurityFinding::SEVERITY_CRITICAL,
        SecurityFinding::SEVERITY_HIGH,
        SecurityFinding::SEVERITY_MEDIUM,
        SecurityFinding::SEVERITY_LOW,
    ];

    public function __construct(
        private readonly SecurityAuditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit non-zero when a finding of this severity or worse exists (critical|high|medium|low)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findings = $this->auditor->run();
        $score = $this->auditor->score($findings);
        $summary = $this->auditor->summary($findings);

        if ($input->getOption('json')) {
            $output->writeln($this->toJson($findings, $score, $summary));

            return $this->exitCode($findings, (string) ($input->getOption('fail-on') ?? ''));
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('CPalius security posture: %d/100', $score));

        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = [
                strtoupper($finding->severity),
                $finding->id,
                $this->translator->trans($finding->titleKey),
                $this->translator->trans($finding->detailKey, $finding->parameters),
            ];
        }
        $io->table(['Severity', 'Check', 'Title', 'Detail'], $rows);

        $io->writeln(sprintf(
            'critical: %d, high: %d, medium: %d, low: %d, pass: %d',
            $summary[SecurityFinding::SEVERITY_CRITICAL],
            $summary[SecurityFinding::SEVERITY_HIGH],
            $summary[SecurityFinding::SEVERITY_MEDIUM],
            $summary[SecurityFinding::SEVERITY_LOW],
            $summary[SecurityFinding::SEVERITY_PASS],
        ));

        $code = $this->exitCode($findings, (string) ($input->getOption('fail-on') ?? ''));

        if ($code === Command::SUCCESS) {
            $io->success('No finding at or above the requested severity.');
        } else {
            $io->error('Findings at or above the requested severity are present.');
        }

        return $code;
    }

    /**
     * @param list<SecurityFinding> $findings
     */
    private function exitCode(array $findings, string $failOn): int
    {
        $failOn = strtolower(trim($failOn));
        if ($failOn === '' || !\in_array($failOn, self::ORDER, true)) {
            return Command::SUCCESS;
        }

        $threshold = array_search($failOn, self::ORDER, true);

        foreach ($findings as $finding) {
            $position = array_search($finding->severity, self::ORDER, true);

            if ($position !== false && $position <= $threshold) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<SecurityFinding> $findings
     * @param array<string, int> $summary
     */
    private function toJson(array $findings, int $score, array $summary): string
    {
        $payload = [
            'score' => $score,
            'summary' => $summary,
            'findings' => array_map(fn (SecurityFinding $finding): array => [
                'id' => $finding->id,
                'severity' => $finding->severity,
                'title' => $this->translator->trans($finding->titleKey),
                'detail' => $this->translator->trans($finding->detailKey, $finding->parameters),
            ], $findings),
        ];

        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
