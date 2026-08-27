<?php

declare(strict_types=1);

namespace App\Core\Cron;

use App\Core\Cron\Dto\VirtualCronJob;
use App\Entity\CronJob;
use App\Repository\CronJobRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CPalius'un Birleşik Otomasyon Motoru: DB-tabanlı (cp_cron_jobs), Attribute
 * Kulvarı (#[CpCronJob]) ve Flat-File Kulvarı (Hooks/cron.{job_name}.php)
 * cron görevlerini TEK bir hibrit "görev sağlayıcı" altında toplayan servis.
 *
 * Bu sınıf İKİ paralel cron altyapısını (biri DB'ye kayıtlı manuel görevler
 * için, biri modül geliştiricilerinin koda gömdüğü görevler için) birleştirme
 * ihtiyacından doğdu — CPalius'un "Sıfır Şişkinlik" anayasasına aykırı olan
 * şey iki ayrı dispatcher/AACP ekranı/whitelist mekanizması işletmekti, iki
 * FARKLI görev KAYNAĞI olması değil. getTasks() bu iki kaynağı okur, geri
 * kalan HER ŞEY (zamanlama kontrolü, subprocess izolasyonu, AACP listesi)
 * kaynak farkını bilmeden tek bir listeye bakar.
 *
 * Kod tabanlı görevler DB'YE HİÇ YAZILMAZ: CronRegistrationPass tarafından
 * derleme zamanında toplanan tanımlardan burada BELLEKTE (in-memory)
 * VirtualCronJob nesneleri üretilir (bkz. runTasks()/getTasks() içindeki
 * $this->cronDefinitions döngüsü) — Manifesto Law 3.1 ruhu: kodun kendisi
 * tek gerçek kaynaktır, DB'de "gölge" bir kayıt YOKTUR.
 *
 * "Son çalıştırma zamanı" kod tabanlı görevler için KALICI OLARAK
 * saklanmaz (VirtualCronJob DTO'su bir request/CLI çalıştırması boyunca
 * yaşar, sonraki çalıştırmada sıfırdan üretilir) — bu bilinçli bir
 * sadeleştirmedir: DB tarafındaki CronJob.lastRunAt'in aksine, kod
 * görevleri için ayrı bir kalıcılık tablosu açmak "Sıfır Şişkinlik"
 * ilkesine aykırı olurdu. AACP panelinde kod görevlerinin "Son Çalıştırma"
 * kolonu bu yüzden her zaman "İzlenmiyor" gösterir.
 */
final class CronManager
{
    /**
     * @param ContainerInterface $serviceLocator #[CpCronJob] taşıyan servisleri
     *   id'leriyle lazy çözen bir ServiceLocator (bkz. CronRegistrationPass).
     * @param list<array{jobName: string, schedule: string, description: string, sourceType: 'attribute'|'flat-file', serviceId: ?string, method: ?string, file: ?string}> $cronDefinitions
     */
    public function __construct(
        private readonly CronJobRepository $cronJobRepository,
        private readonly ContainerInterface $serviceLocator,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly array $cronDefinitions,
    ) {
    }

    /**
     * Sistemdeki TÜM cron görevlerini (DB + kod tabanlı) tek bir listede
     * döner. AACP "Cron Yönetimi" ekranı ve RunDueCronJobsCommand dispatcher'ı
     * BU metodu kullanır — hiçbiri CronJobRepository'yi veya cronDefinitions'ı
     * doğrudan okumaz (tek gerçek kaynak burasıdır).
     *
     * @return list<CronJob|VirtualCronJob>
     */
    public function getTasks(): array
    {
        $tasks = [];

        foreach ($this->cronJobRepository->findAllOrdered() as $cronJob) {
            $tasks[] = $cronJob;
        }

        foreach ($this->cronDefinitions as $definition) {
            $tasks[] = new VirtualCronJob(
                jobName: $definition['jobName'],
                description: $definition['description'],
                cronExpression: $definition['schedule'],
                sourceType: $definition['sourceType'],
                sourceDetail: $definition['sourceType'] === 'attribute'
                    ? sprintf('%s::%s()', $definition['serviceId'], $definition['method'])
                    : (string) $definition['file'],
            );
        }

        return $tasks;
    }

    /**
     * jobName'e karşılık gelen sanal (kod tabanlı) görevin ham tanımını
     * döner — RunVirtualCronJobCommand (izole subprocess köprüsü) BU metotla
     * hangi kulvarı (attribute/flat-file) çalıştıracağını bulur.
     *
     * @return array{jobName: string, schedule: string, description: string, sourceType: 'attribute'|'flat-file', serviceId: ?string, method: ?string, file: ?string}|null
     */
    public function findDefinitionByJobName(string $jobName): ?array
    {
        foreach ($this->cronDefinitions as $definition) {
            if ($definition['jobName'] === $jobName) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * jobName'e karşılık gelen sanal görevi SENKRON olarak, ÇAĞIRANIN kendi
     * process'i içinde çalıştırır ve çıktısını (string) döner.
     *
     * BİLİNÇLİ olarak subprocess AÇMAZ: izolasyon (bkz. Manifesto Law 2.1)
     * RunVirtualCronJobCommand'in KENDİSİNİN ayrı bir "php bin/console
     * cp:cron:run-virtual <jobName>" alt-process'i olarak tetiklenmesiyle
     * sağlanır (bkz. CronCommandProcessFactory kullanımı) — bu metot o
     * alt-process'in İÇİNDE, tek bir görevi çalıştırmak için çağrılır.
     *
     * @throws \RuntimeException jobName tanınmıyorsa veya görev çalışırken hata verirse.
     */
    public function runVirtualTask(string $jobName): string
    {
        $definition = $this->findDefinitionByJobName($jobName);

        if ($definition === null) {
            throw new \RuntimeException(sprintf('"%s" adında bir sanal cron görevi bulunamadı.', $jobName));
        }

        try {
            return $definition['sourceType'] === 'attribute'
                ? $this->runAttributeTask($definition)
                : $this->runFlatFileTask($definition);
        } catch (Throwable $e) {
            $this->quarantineTaskFailure($jobName, $e);

            throw new \RuntimeException(sprintf('"%s" görevi çalışırken hata oluştu: %s', $jobName, $e->getMessage()), previous: $e);
        }
    }

    /**
     * @param array{jobName: string, schedule: string, description: string, sourceType: 'attribute', serviceId: ?string, method: ?string, file: ?string} $definition
     */
    private function runAttributeTask(array $definition): string
    {
        $serviceId = (string) $definition['serviceId'];
        $method = (string) $definition['method'];

        if (!$this->serviceLocator->has($serviceId)) {
            throw new \RuntimeException(sprintf('"%s" servisi konteynerde bulunamadı.', $serviceId));
        }

        $service = $this->serviceLocator->get($serviceId);
        $result = $service->$method();

        return is_string($result) ? $result : '';
    }

    /**
     * Flat-file cron dosyasını, HookManager::includeIsolated() ile AYNI
     * izolasyon prensibiyle (dış scope'a erişimi olmayan kapatılmış bir
     * Closure içinde) çalıştırır. Dosya, 'run' anahtarında bir Closure
     * TAŞIYAN bir dizi döndürmelidir (bkz. örnek dosya
     * cron.publish_scheduled_example.php) — CronRegistrationPass derleme
     * zamanında sadece 'schedule'/'description' anahtarlarını okur, 'run'
     * closure'ı İSE SADECE burada, gerçek çalıştırma anında invoke edilir.
     *
     * @param array{jobName: string, schedule: string, description: string, sourceType: 'flat-file', serviceId: ?string, method: ?string, file: ?string} $definition
     */
    private function runFlatFileTask(array $definition): string
    {
        $file = (string) $definition['file'];

        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('"%s" dosyası bulunamadı.', $file));
        }

        $isolated = static function (string $__cp_cron_file): mixed {
            return include $__cp_cron_file;
        };

        $definitionArray = $isolated($file);

        if (!is_array($definitionArray) || !isset($definitionArray['run']) || !($definitionArray['run'] instanceof \Closure)) {
            throw new \RuntimeException('Flat-file cron dosyası "run" anahtarında bir Closure döndürmüyor.');
        }

        $result = ($definitionArray['run'])();

        return is_string($result) ? $result : '';
    }

    private function quarantineTaskFailure(string $jobName, Throwable $e): void
    {
        $this->logger->error('Kod tabanlı cron görevi çalıştırılırken hata oluştu.', [
            'job_name' => $jobName,
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] "%s" kod tabanlı cron görevi çalışma anında hata verdi. Sebep: %s',
            date('Y-m-d H:i:s'),
            $jobName,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
