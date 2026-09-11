<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Update\UpdateRunner;
use App\Core\Update\UpdateStepResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The one command to run after the code changed.
 *
 * Before this existed, "deploying" meant remembering an ordered list by hand:
 * migrate, then whatever data fix the release notes mentioned, then toggle
 * every module whose version moved so its upgrade() would fire, then import
 * configuration, then clear the cache. Forgetting a step produced no error —
 * only behaviour nobody could explain weeks later.
 *
 * --dry-run answers "what would this do?" without touching anything, which is
 * what makes it safe to run on production before running it for real.
 */
#[AsCommand(
    name: 'cp:update',
    description: 'Applies pending migrations, update hooks, module upgrades and configuration, then clears the cache.',
)]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly UpdateRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without applying anything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $results = $this->runner->run($dryRun);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'dry_run' => $dryRun,
                'ok' => !$this->hasFailure($results),
                'steps' => array_map(static fn (UpdateStepResult $r): array => $r->toArray(), $results),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return $this->hasFailure($results) ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title($dryRun ? 'CPalius update — dry run' : 'CPalius update');

        foreach ($results as $result) {
            $this->renderStep($io, $result);
        }

        if ($this->hasFailure($results)) {
            $io->error('Update incomplete. Fix the reported problem and run cp:update again — completed work is skipped.');

            return Command::FAILURE;
        }

        $changed = array_filter($results, static fn (UpdateStepResult $r): bool => $r->changedAnything());

        if ($changed === []) {
            $io->success('Already up to date.');

            return Command::SUCCESS;
        }

        $io->success($dryRun
            ? sprintf('%d step(s) would change something. Run without --dry-run to apply.', \count($changed))
            : sprintf('%d step(s) applied.', \count($changed)));

        return Command::SUCCESS;
    }

    private function renderStep(SymfonyStyle $io, UpdateStepResult $result): void
    {
        $io->section($result->step);

        if ($result->isFailure()) {
            $io->warning($result->summary);
        } else {
            $io->writeln($result->summary);
        }

        if ($result->details !== []) {
            $io->listing($result->details);
        }
    }

    /**
     * @param list<UpdateStepResult> $results
     */
    private function hasFailure(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->isFailure()) {
                return true;
            }
        }

        return false;
    }
}
