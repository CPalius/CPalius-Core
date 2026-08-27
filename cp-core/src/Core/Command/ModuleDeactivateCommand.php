<?php

namespace App\Core\Command;

use App\Core\Module\ActiveModulesFileWriter;
use App\Core\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'cp:module:deactivate',
    description: 'Belirtilen modülü active_modules.php dosyasından çıkararak pasifize eder.',
)]
final class ModuleDeactivateCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ActiveModulesFileWriter $fileWriter,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module-name', InputArgument::REQUIRED, 'Pasifize edilecek modülün klasör adı (ör. Blog)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moduleName = (string) $input->getArgument('module-name');

        $modules = $this->moduleRegistry->discoverAllModules();
        $target = null;
        foreach ($modules as $module) {
            if (strcasecmp($module['dirName'], $moduleName) === 0 || strcasecmp($module['name'], $moduleName) === 0) {
                $target = $module;
                break;
            }
        }

        if ($target === null || $target['class'] === null) {
            $io->error(sprintf('"%s" adında bir modül bulunamadı. cp:module:list ile mevcut modülleri görebilirsiniz.', $moduleName));

            return Command::FAILURE;
        }

        if ($target['status'] !== 'active') {
            $io->note(sprintf('"%s" modülü zaten aktif değil.', $target['name']));

            return Command::SUCCESS;
        }

        $this->fileWriter->remove($target['class']);

        $io->success(sprintf('"%s" modülü pasifize edildi.', $target['name']));

        $this->clearCache($io);

        return Command::SUCCESS;
    }

    private function clearCache(SymfonyStyle $io): void
    {
        $io->text('Önbellek temizleniyor...');

        $phpBinary = (new PhpExecutableFinder())->find();
        $consolePath = $this->projectDir.'/cp-core/bin/console';

        $process = new Process([$phpBinary ?: 'php', $consolePath, 'cache:clear']);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->warning('Önbellek otomatik temizlenemedi, manuel olarak "cp-core/bin/console cache:clear" çalıştırmanız gerekebilir.');

            return;
        }

        $io->text('Önbellek başarıyla temizlendi.');
    }
}
