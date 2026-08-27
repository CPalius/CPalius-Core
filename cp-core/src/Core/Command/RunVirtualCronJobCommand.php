<?php

declare(strict_types=1);

namespace App\Core\Command;

use App\Core\Cron\CronManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Kod tabanlı (Attribute veya Flat-File kulvarı) bir "Sanal Cron Görevi"nin
 * TEK OS-seviyesi giriş noktası — DB-tabanlı görevlerin "php bin/console
 * cp:xyz" ile doğrudan kendi komutlarını subprocess olarak çalıştırmasının
 * (bkz. CronCommandProcessFactory) kod tabanlı görevler için KARŞILIĞI.
 *
 * Kod tabanlı bir görevin arkasında kendi #[AsCommand]'ı YOKTUR (sadece bir
 * servis metodu veya flat-file closure'ı) — bu komut, jobName argümanını
 * alıp CronManager::runVirtualTask() üzerinden doğru kulvara yönlendiren
 * TEK KÖPRÜDÜR. Böylece hem otomatik dispatcher (RunDueCronJobsCommand) hem
 * de AACP'nin "Şimdi Çalıştır" ucu, kod tabanlı görevleri de AYNI izole
 * subprocess mekanizmasıyla (Process component, ayrı PHP process'i)
 * çalıştırabilir — bir kod görevinin çökmesi/sonsuz döngüye girmesi bu
 * dispatcher'ın kendisini asla etkilemez (Manifesto Law 2.1 ruhu).
 */
#[AsCommand(
    name: 'cp:cron:run-virtual',
    description: 'Kod tabanlı (Attribute/Flat-File) bir sanal cron görevini jobName ile çalıştırır.',
)]
final class RunVirtualCronJobCommand extends Command
{
    public function __construct(
        private readonly CronManager $cronManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobName', InputArgument::REQUIRED, 'CronManager::findDefinitionByJobName() ile eşleşen sanal görev adı (ör. "blog.publish_scheduled").');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobName = (string) $input->getArgument('jobName');

        try {
            $result = $this->cronManager->runVirtualTask($jobName);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($result !== '') {
            $output->writeln($result);
        }

        $io->success(sprintf('"%s" sanal cron görevi çalıştırıldı.', $jobName));

        return Command::SUCCESS;
    }
}
