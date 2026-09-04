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
 * Hybrid task provider: DB (cp_cron_jobs), #[CpCronJob], and Hooks/cron.{job_name}.php.
 * Code jobs stay in-memory (Law 3.1); last-run is not persisted for the code track.
 */
final class CronManager
{
    /**
     * @param ContainerInterface $serviceLocator Lazy locator for #[CpCronJob] services.
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
     * Unified DB + code task list; AACP and the dispatcher read only this.
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
     * Raw virtual-job definition for RunVirtualCronJobCommand (attribute vs flat-file).
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
     * Run one virtual job in the current process (isolation is the outer cp:cron:run-virtual subprocess).
     *
     * @throws \RuntimeException When the job name is unknown or the task throws.
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
     * Include the cron file in an isolated Closure and invoke its 'run' key (same idea as HookManager).
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
