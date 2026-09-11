<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Cron\CronManager;
use App\Core\Entity\EntityTypeRegistry;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Hook\HookManager;
use App\Core\Resource\ResourceRegistry;
use App\Core\Security\CapabilityRegistry;
use App\Core\Security\RoleConfigManager;
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
 * Shows what the compile-time registries actually contain.
 *
 * CPalius discovers most of itself through attributes: #[CpHook], #[CpCronJob],
 * #[CpResource], #[CpEntityType], #[CpFieldType], capabilities.yaml. That gives
 * a module a lot of reach with very little ceremony — and leaves a developer
 * with no way to answer "did my thing register?" except by triggering it and
 * seeing whether something happens. A typo in a hook point or a missing tag
 * produces silence, not an error.
 *
 * One command with a topic argument rather than six near-identical classes: the
 * differences between them are two lines of data shaping each, and six files
 * would drift apart the moment one of them grew a feature.
 */
#[AsCommand(
    name: 'cp:debug',
    description: 'Lists what the registries discovered: capabilities, hooks, cron jobs, entity types, fields, resources.',
)]
final class DebugCommand extends Command
{
    private const TOPICS = ['capabilities', 'hooks', 'cron', 'entity-types', 'fields', 'resources'];

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly RoleConfigManager $roles,
        private readonly HookManager $hooks,
        private readonly CronManager $cron,
        private readonly EntityTypeRegistry $entityTypes,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly FieldDefinitionRegistry $fieldDefinitions,
        private readonly ResourceRegistry $resources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('topic', InputArgument::REQUIRED, 'One of: '.implode(', ', self::TOPICS))
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Case-insensitive substring to match')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output');
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('topic')) {
            $suggestions->suggestValues(self::TOPICS);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $topic = strtolower(trim((string) $input->getArgument('topic')));

        if (!\in_array($topic, self::TOPICS, true)) {
            $io->error(sprintf('Unknown topic "%s". Use one of: %s', $topic, implode(', ', self::TOPICS)));

            return Command::INVALID;
        }

        [$headers, $rows] = $this->collect($topic);

        $filter = $input->getOption('filter');

        if (\is_string($filter) && trim($filter) !== '') {
            $needle = mb_strtolower(trim($filter));
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains(mb_strtolower(implode(' ', array_map('strval', $row))), $needle),
            ));
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'topic' => $topic,
                'count' => \count($rows),
                'rows' => array_map(
                    static fn (array $row): array => array_combine($headers, array_map('strval', $row)),
                    $rows,
                ),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title(sprintf('cp:debug %s', $topic));

        if ($rows === []) {
            $io->warning('Nothing registered.');

            return Command::SUCCESS;
        }

        $io->table($headers, $rows);
        $io->writeln(sprintf('<info>%d entr%s.</info>', \count($rows), \count($rows) === 1 ? 'y' : 'ies'));

        return Command::SUCCESS;
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function collect(string $topic): array
    {
        return match ($topic) {
            'capabilities' => $this->capabilityRows(),
            'hooks' => $this->hookRows(),
            'cron' => $this->cronRows(),
            'entity-types' => $this->entityTypeRows(),
            'fields' => $this->fieldRows(),
            'resources' => $this->resourceRows(),
            default => [[], []],
        };
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function capabilityRows(): array
    {
        // Which roles hold a capability is the question actually being asked
        // most of the time — "why can the editor not do X" is answered here
        // rather than by reading four YAML files.
        $holders = [];

        foreach ($this->roles->getAllRoleIds() as $roleId) {
            foreach ($this->roles->getCapabilitiesForRole($roleId) as $capability) {
                $holders[$capability][] = $roleId;
            }
        }

        $rows = [];

        foreach ($this->capabilities->all() as $capability) {
            $granted = $holders[$capability] ?? [];

            $rows[] = [
                $capability,
                $this->capabilities->getSource($capability) ?? 'core',
                $granted === [] ? '—' : implode(', ', $granted),
            ];
        }

        return [['Capability', 'Source', 'Held by'], $rows];
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function hookRows(): array
    {
        $rows = [];

        foreach ($this->hooks->discoverAll() as $hook) {
            $rows[] = [
                $hook['hookPoint'],
                $hook['type'],
                $hook['detail'],
            ];
        }

        return [['Hook point', 'Type', 'Listener'], $rows];
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function cronRows(): array
    {
        $rows = [];

        // getTasks() mixes persisted CronJob entities with in-memory
        // VirtualCronJob DTOs. They deliberately share a read API rather than
        // an interface, so the getters are what both are guaranteed to answer.
        foreach ($this->cron->getTasks() as $task) {
            $rows[] = [
                $task->getName(),
                $task->getCronExpression(),
                $task->isCodeBased() ? 'code' : 'database',
                $task->isActive() ? 'active' : 'disabled',
                method_exists($task, 'getSourceDetail') ? $task->getSourceDetail() : '',
            ];
        }

        return [['Job', 'Schedule', 'Origin', 'State', 'Source'], $rows];
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function entityTypeRows(): array
    {
        $rows = [];

        foreach ($this->entityTypes->all() as $definition) {
            $rows[] = [
                $definition->id,
                $definition->className,
                $this->flags([
                    'fieldable' => $definition->fieldable,
                    'bundleable' => $definition->bundleable,
                    'revisionable' => $definition->revisionable,
                    'translatable' => $definition->translatable,
                ]),
            ];
        }

        return [['Id', 'Class', 'Capabilities'], $rows];
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function fieldRows(): array
    {
        $rows = [];

        // Registered types first: a field definition naming a type that is not
        // registered is one of the quieter ways a bundle stops rendering.
        foreach ($this->fieldTypes->ids() as $id) {
            $rows[] = ['type', $id, '', ''];
        }

        foreach ($this->fieldDefinitions->allBundles() as $bundle) {
            foreach ($this->fieldDefinitions->getFieldsForBundle($bundle) as $definition) {
                $rows[] = [
                    'field',
                    $definition->getName(),
                    $bundle,
                    $definition->getType().($this->fieldTypes->has($definition->getType()) ? '' : ' (UNREGISTERED)'),
                ];
            }
        }

        return [['Kind', 'Name', 'Bundle', 'Type'], $rows];
    }

    /**
     * @return array{list<string>, list<list<string>>}
     */
    private function resourceRows(): array
    {
        $rows = [];

        foreach ($this->resources->all() as $definition) {
            $rows[] = [
                $definition->name,
                $definition->entityClass,
                $definition->module,
                $this->flags([
                    'auditable' => $definition->auditable,
                    'multi-tenant' => $definition->multiTenant,
                    'publishable' => $definition->publishable,
                    'soft-delete' => $definition->softDeletable,
                ]),
                $definition->workflow ?? '—',
            ];
        }

        return [['Resource', 'Entity', 'Module', 'Traits', 'Workflow'], $rows];
    }

    /**
     * @param array<string, bool> $flags
     */
    private function flags(array $flags): string
    {
        $enabled = array_keys(array_filter($flags));

        return $enabled === [] ? '—' : implode(', ', $enabled);
    }
}
