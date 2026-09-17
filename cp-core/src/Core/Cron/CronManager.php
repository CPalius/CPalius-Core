<?php

declare(strict_types=1);

namespace App\Core\Cron;

use App\Core\Cron\Dto\VirtualCronJob;
use App\Entity\CronJob;
use App\Repository\CronJobRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hybrid task provider: DB (cp_cron_jobs), #[CpCronJob], and Hooks/cron.{job_name}.php.
 * Code jobs stay in-memory (Law 3.1); last-run is not persisted for the code track.
 */
final class CronManager
{
    /**
     * @param ContainerInterface                                                                                                                                           $serviceLocator  lazy locator for #[CpCronJob] services
     * @param list<array{jobName: string, schedule: string, description: string, sourceType: 'attribute'|'flat-file', serviceId: ?string, method: ?string, file: ?string}> $cronDefinitions
     */
    public function __construct(
        private readonly CronJobRepository $cronJobRepository,
        private readonly ContainerInterface $serviceLocator,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly array $cronDefinitions,
        private readonly CronOverrideStore $overrides,
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

        $overrides = $this->overrides->all();

        foreach ($this->cronDefinitions as $definition) {
            $override = $overrides[$definition['jobName']] ?? null;

            $tasks[] = new VirtualCronJob(
                jobName: $definition['jobName'],
                description: $definition['description'],
                cronExpression: $override['schedule'] ?? $definition['schedule'],
                sourceType: $definition['sourceType'],
                sourceDetail: $definition['sourceType'] === 'attribute'
                    ? sprintf('%s::%s()', $definition['serviceId'], $definition['method'])
                    : (string) $definition['file'],
                declaredExpression: $definition['schedule'],
                active: $override['active'] ?? true,
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
     * @throws \RuntimeException when the job name is unknown or the task throws
     */
    public function runVirtualTask(string $jobName): string
    {
        $definition = $this->findDefinitionByJobName($jobName);

        if ($definition === null) {
            throw new \RuntimeException(sprintf('No virtual cron job found with name "%s".', $jobName));
        }

        try {
            return $definition['sourceType'] === 'attribute'
                ? $this->runAttributeTask($definition)
                : $this->runFlatFileTask($definition);
        } catch (\Throwable $e) {
            $this->quarantineTaskFailure($jobName, $e);

            throw new \RuntimeException(sprintf('Error while running job "%s": %s', $jobName, $e->getMessage()), previous: $e);
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
            throw new \RuntimeException(sprintf('Service "%s" not found in the container.', $serviceId));
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
            throw new \RuntimeException(sprintf('File "%s" not found.', $file));
        }

        $isolated = static function (string $__cp_cron_file): mixed {
            return include $__cp_cron_file;
        };

        $definitionArray = $isolated($file);

        if (!is_array($definitionArray) || !isset($definitionArray['run']) || !($definitionArray['run'] instanceof \Closure)) {
            throw new \RuntimeException('Flat-file cron file does not return a Closure under the "run" key.');
        }

        $result = ($definitionArray['run'])();

        return is_string($result) ? $result : '';
    }

    private function quarantineTaskFailure(string $jobName, \Throwable $e): void
    {
        $this->logger->error('Error while running code-based cron job.', [
            'job_name' => $jobName,
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] Code-based cron job "%s" failed at runtime. Reason: %s',
            date('Y-m-d H:i:s'),
            $jobName,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
