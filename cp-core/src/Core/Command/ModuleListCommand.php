<?php

namespace App\Core\Command;

use App\Core\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cp:module:list',
    description: 'cp-content/modules altındaki tüm modülleri ve durumlarını listeler.',
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
            $io->warning('cp-content/modules altında hiçbir modül bulunamadı.');

            return Command::SUCCESS;
        }

        $io->title('CPalius Modülleri');

        $rows = [];
        foreach ($modules as $module) {
            $rows[] = [
                $module['name'],
                $module['version'],
                $this->formatStatus($module['status']),
                $module['reason'] ?? '',
            ];
        }

        $io->table(['Ad', 'Versiyon', 'Durum', 'Not'], $rows);

        $activeCount = count(array_filter($modules, static fn (array $m) => $m['status'] === 'active'));
        $quarantinedCount = count(array_filter($modules, static fn (array $m) => $m['status'] === 'quarantined'));

        $io->text(sprintf(
            'Toplam: %d modül | Aktif: %d | Pasif: %d | Karantinada: %d',
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
            'active' => '<fg=green;options=bold>● Aktif</>',
            'inactive' => '<fg=yellow>○ Pasif</>',
            'quarantined' => '<fg=red;options=bold>✕ Karantinada</>',
            default => $status,
        };
    }
}
