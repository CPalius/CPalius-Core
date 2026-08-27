<?php

namespace App\Core\Command;

use App\Core\Module\ModuleActivator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cp:module:activate',
    description: 'Belirtilen modülü doğrular ve active_modules.php dosyasına ekleyerek aktive eder.',
)]
final class ModuleActivateCommand extends Command
{
    public function __construct(
        private readonly ModuleActivator $moduleActivator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module-name', InputArgument::REQUIRED, 'Aktive edilecek modülün klasör adı (ör. Blog)');
    }

    /**
     * Asıl doğrulama/aktivasyon mantığı ModuleActivator'da yaşar — bu
     * komut ve AACPController::activateModule() AYNI servisi kullanır,
     * böylece web'den aktivasyon da CLI ile birebir aynı dry-run/lint
     * güvencesine (Manifesto Law 2.2) sahip olur.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moduleName = (string) $input->getArgument('module-name');

        $io->section(sprintf('"%s" modülü için ön kontrol (dry-run) başlatılıyor...', $moduleName));

        $result = $this->moduleActivator->activate($moduleName);

        if (!$result['success']) {
            $io->error($result['message']);
            if ($result['output'] !== null) {
                $io->block($result['output'], 'HATA ÇIKTISI', 'fg=red', ' ', true);
            }

            return Command::FAILURE;
        }

        $io->success($result['message']);

        return Command::SUCCESS;
    }
}
