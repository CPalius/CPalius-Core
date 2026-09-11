<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cp:module:list',
    description: 'Lists all modules under cp-content/modules and their statuses.',
)]
final class ModuleListCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $modules = $this->moduleRegistry->discoverAllModules();

        if ($modules === []) {
            $io->warning('No modules found under cp-content/modules.');

            return Command::SUCCESS;
        }

        $io->title('CPalius Modules');

        $rows = [];
        foreach ($modules as $module) {
            $rows[] = [
                $module['name'],
                $module['version'],
                $this->formatStatus($module['status']),
                $module['reason'] ?? '',
            ];
        }

        $io->table(['Name', 'Version', 'Status', 'Note'], $rows);

        $activeCount = count(array_filter($modules, static fn (array $m) => $m['status'] === 'active'));
        $quarantinedCount = count(array_filter($modules, static fn (array $m) => $m['status'] === 'quarantined'));

        $io->text(sprintf(
            'Total: %d modules | Active: %d | Inactive: %d | Quarantined: %d',
            count($modules),
            $activeCount,
            count($modules) - $activeCount - $quarantinedCount,
            $quarantinedCount,
        ));

        return Command::SUCCESS;
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'active' => '<fg=green;options=bold>● Active</>',
            'inactive' => '<fg=yellow>○ Inactive</>',
            'quarantined' => '<fg=red;options=bold>✕ Quarantined</>',
            default => $status,
        };
    }
}
