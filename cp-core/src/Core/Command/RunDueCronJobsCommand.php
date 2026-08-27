<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Cron\CronDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CPalius'un Birleşik Otomasyon Motoru'nun TEK gerçek OS-seviyesi giriş
 * noktası. Gerçek dispatch mantığı BURADA DEĞİL, CronDispatcher::runDueTasks()
 * içinde yaşar (bkz. o sınıfın docblock'u) — bu komut sadece sonuçları
 * SymfonyStyle ile terminale basar. Aynı CronDispatcher, HTTP pseudo-cron
 * ucu (/cron/execute, bkz. CronExecuteController) tarafından da kullanılır.
 *
 * Gerçek sunucuda BİR TEK crontab/Task Scheduler girdisi (ör. her dakika)
 * bu komutu (veya HTTP ucunu) tetikler.
 */
#[AsCommand(
    name: 'cp:cron:run',
    description: 'DB (cp_cron_jobs) VE kod tabanlı (Attribute/Flat-File) TÜM cron görevlerinden zamanı gelmiş olanları çalıştırır.',
)]
final class RunDueCronJobsCommand extends Command
{
    public function __construct(
        private readonly CronDispatcher $cronDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $results = $this->cronDispatcher->runDueTasks();

        if ($results === []) {
            $io->comment('Çalışma zamanı gelmiş aktif cron görevi yok.');

            return Command::SUCCESS;
        }

        foreach ($results as $result) {
            $prefix = $result['sourceType'] === 'code' ? '[KOD] ' : '';
            $label = $prefix.$result['jobName'];

            if ($result['success']) {
                $io->success(sprintf('%s: %s', $label, $result['message']));
            } else {
                $io->error(sprintf('%s: %s', $label, $result['message']));
            }
        }

        return Command::SUCCESS;
    }
}
