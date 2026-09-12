<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Migrate\MigrationInterface;
use App\Core\Migrate\MigrationRegistry;
use App\Core\Migrate\MigrationReport;
use App\Core\Migrate\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs imports from the shell.
 *
 * In Drupal the Migrate API ships without a way to run it: the core module
 * provides the engine and you install migrate_tools from contrib to get drush
 * commands, while the core UI only handles Drupal-to-Drupal upgrades. An engine
 * an operator cannot invoke is not a feature they have. This lives in core.
 *
 * The default is a DRY RUN of nothing being written — `run` requires --apply to
 * touch data. That is the opposite of most importers and it is deliberate: the
 * cost of accidentally dry-running is reading a report, the cost of
 * accidentally importing 40 000 rows into a live site is a restore from backup.
 */
#[AsCommand(
    name: 'cp:migrate',
    description: 'Lists, dry-runs, applies and rolls back data imports.',
)]
final class MigrateCommand extends Command
{
    private const ACTIONS = ['list', 'run', 'status', 'rollback'];

    public function __construct(
        private readonly MigrationRegistry $registry,
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'One of: '.implode(', ', self::ACTIONS), 'list')
            ->addArgument('migration', InputArgument::OPTIONAL, 'Migration id (omit with "run" to run every migration in dependency order)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually write. Without it, run and rollback only report what they would do')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many source rows')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output');
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('action')) {
            $suggestions->suggestValues(self::ACTIONS);

            return;
        }

        if ($input->mustSuggestArgumentValuesFor('migration')) {
            $suggestions->suggestValues(array_keys($this->registry->all()));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $json = (bool) $input->getOption('json');

        if (!\in_array($action, self::ACTIONS, true)) {
            $io->error(sprintf('Unknown action "%s". Expected one of: %s.', $action, implode(', ', self::ACTIONS)));

            return Command::INVALID;
        }

        try {
            return match ($action) {
                'list' => $this->listMigrations($io, $json),
                'status' => $this->status($io, $input, $json),
                'run' => $this->runImport($io, $input, $json),
                'rollback' => $this->rollback($io, $input, $json),
            };
        } catch (\InvalidArgumentException|\LogicException $e) {
            // Unknown id, unknown dependency, dependency cycle: all operator
            // input problems, all worth saying plainly rather than as a trace.
            $io->error($e->getMessage());

            return Command::INVALID;
        }
    }

    private function listMigrations(SymfonyStyle $io, bool $json): int
    {
        $migrations = $this->registry->all();

        if ($migrations === []) {
            if ($json) {
                $io->writeln($this->encode(['migrations' => []]));

                return Command::SUCCESS;
            }

            $io->warning('No migrations are registered. A migration is a service implementing MigrationInterface.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($this->registry->ordered() as $migration) {
            $rows[] = [
                'id' => $migration->id(),
                'label' => $migration->label(),
                'source' => $migration->source()->describe(),
                'destination' => $migration->destination()->describe(),
                'dependsOn' => $migration->dependsOn(),
                'imported' => $this->runner->importedCount($migration),
            ];
        }

        if ($json) {
            $io->writeln($this->encode(['migrations' => $rows]));

            return Command::SUCCESS;
        }

        $io->title('Registered migrations (dependency order)');
        $io->table(
            ['Id', 'Label', 'Source', 'Destination', 'Depends on', 'Imported'],
            array_map(static fn (array $r): array => [
                $r['id'],
                $r['label'],
                $r['source'],
                $r['destination'],
                $r['dependsOn'] === [] ? '—' : implode(', ', $r['dependsOn']),
                (string) $r['imported'],
            ], $rows),
        );

        return Command::SUCCESS;
    }

    private function status(SymfonyStyle $io, InputInterface $input, bool $json): int
    {
        $migration = $this->requireMigration($input);

        $payload = [
            'migration' => $migration->id(),
            'label' => $migration->label(),
            'source' => $migration->source()->describe(),
            'destination' => $migration->destination()->describe(),
            'sourceRows' => $migration->source()->count(),
            'imported' => $this->runner->importedCount($migration),
        ];

        if ($json) {
            $io->writeln($this->encode($payload));

            return Command::SUCCESS;
        }

        $io->title(sprintf('%s — %s', $migration->id(), $migration->label()));
        $io->definitionList(
            ['Source' => $payload['source']],
            ['Destination' => $payload['destination']],
            ['Source rows' => $payload['sourceRows'] === null ? 'unknown (streamed)' : (string) $payload['sourceRows']],
            ['Imported so far' => (string) $payload['imported']],
        );

        return Command::SUCCESS;
    }

    private function runImport(SymfonyStyle $io, InputInterface $input, bool $json): int
    {
        $apply = (bool) $input->getOption('apply');
        $limit = $this->limit($input);
        $id = $input->getArgument('migration');

        $migrations = \is_string($id) && $id !== ''
            ? $this->registry->orderOf([$id])
            : $this->registry->ordered();

        if ($migrations === []) {
            $io->warning('No migrations are registered.');

            return Command::SUCCESS;
        }

        $reports = [];

        foreach ($migrations as $migration) {
            $reports[] = $this->runner->run($migration, !$apply, $limit);
        }

        return $this->renderReports($io, $reports, $json, $apply, 'import');
    }

    private function rollback(SymfonyStyle $io, InputInterface $input, bool $json): int
    {
        $migration = $this->requireMigration($input);
        $apply = (bool) $input->getOption('apply');

        $report = $this->runner->rollback($migration, !$apply);

        return $this->renderReports($io, [$report], $json, $apply, 'rollback');
    }

    /**
     * @param list<MigrationReport> $reports
     */
    private function renderReports(SymfonyStyle $io, array $reports, bool $json, bool $apply, string $verb): int
    {
        $failed = array_filter($reports, static fn (MigrationReport $r): bool => $r->hasFailures());

        if ($json) {
            $io->writeln($this->encode([
                'applied' => $apply,
                'ok' => $failed === [],
                'reports' => array_map(static fn (MigrationReport $r): array => $r->toArray(), $reports),
            ]));

            return $failed === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title($apply ? sprintf('CPalius %s', $verb) : sprintf('CPalius %s — dry run, nothing written', $verb));

        foreach ($reports as $report) {
            $io->section($report->migrationId);
            $io->table(
                ['Processed', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Failed'],
                [[
                    (string) $report->processed(),
                    (string) $report->created(),
                    (string) $report->updated(),
                    (string) $report->unchanged(),
                    (string) $report->skipped(),
                    (string) $report->failed(),
                ]],
            );

            if ($report->limitReached()) {
                $io->comment('Row limit reached; the rest of the source was not read.');
            }

            foreach (\array_slice($report->failures(), 0, 20) as $failure) {
                $io->writeln(sprintf('  <fg=red>source %s</> — %s', $failure['sourceId'], $failure['message']));
            }

            if ($report->failed() > 20) {
                $io->writeln(sprintf('  … and %d more failed rows.', $report->failed() - 20));
            }
        }

        if ($failed !== []) {
            $io->error('Some rows failed. They are listed by source id; the rest of the run completed and can be re-run safely.');

            return Command::FAILURE;
        }

        if (!$apply) {
            $io->success('Dry run complete. Re-run with --apply to write.');

            return Command::SUCCESS;
        }

        $io->success(ucfirst($verb).' complete.');

        return Command::SUCCESS;
    }

    private function requireMigration(InputInterface $input): MigrationInterface
    {
        $id = $input->getArgument('migration');

        if (!\is_string($id) || $id === '') {
            throw new \InvalidArgumentException('This action needs a migration id. Run "cp:migrate list" to see them.');
        }

        return $this->registry->get($id);
    }

    private function limit(InputInterface $input): ?int
    {
        $raw = $input->getOption('limit');

        if ($raw === null) {
            return null;
        }

        if (!is_numeric($raw) || (int) $raw < 1) {
            // Silently ignoring a bad limit would import the whole source when
            // the operator asked for ten rows.
            throw new \InvalidArgumentException(sprintf('--limit must be a positive whole number, got "%s".', (string) $raw));
        }

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
