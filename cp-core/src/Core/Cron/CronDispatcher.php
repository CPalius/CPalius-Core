<?php

declare(strict_types=1);

namespace App\Core\Cron;

use App\Core\Cron\Dto\VirtualCronJob;
use App\Entity\CronJob;
use App\Entity\CronJobRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs due DB + code tasks from CronManager::getTasks(). Shared by CLI and HTTP pseudo-cron.
 *
 * @see \App\Core\Command\RunDueCronJobsCommand CLI entry.
 * @see \App\Controller\CronExecuteController HTTP entry (CRON_TOKEN).
 */
final class CronDispatcher
{
    public function __construct(
        private readonly CronManager $cronManager,
        private readonly CronExpressionEvaluator $cronExpressionEvaluator,
        private readonly CronCommandWhitelist $cronCommandWhitelist,
        private readonly CronCommandProcessFactory $cronCommandProcessFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Run every due task and return one result row per job for CLI/HTTP formatting.
     *
     * @return list<array{jobName: string, sourceType: 'db'|'code', success: bool, message: string}>
     */
    public function runDueTasks(): array
    {
        $now = new \DateTimeImmutable();

        $dueTasks = array_filter(
            $this->cronManager->getTasks(),
            function (CronJob|VirtualCronJob $task) use ($now): bool {
                if ($task instanceof CronJob && !$task->isActive()) {
                    return false;
                }

                return $this->cronExpressionEvaluator->isDue($task->getCronExpression(), $now);
            },
        );

        $results = [];

        foreach ($dueTasks as $task) {
            $results[] = $task instanceof CronJob
                ? $this->runDatabaseJob($task, $now)
                : $this->runVirtualJob($task);
        }

        if ($results !== []) {
            $this->entityManager->flush();
        }

        return $results;
    }

    /**
     * @return array{jobName: string, sourceType: 'db', success: bool, message: string}
     */
    private function runDatabaseJob(CronJob $job, \DateTimeImmutable $now): array
    {
        $run = new CronJobRun($job, triggeredManually: false);
        $this->entityManager->persist($run);

        if (!$this->cronCommandWhitelist->isAllowed($job->getCommandName())) {
            $message = sprintf('"%s" izin verilen (whitelist) komutlar arasında değil, çalıştırılmadı.', $job->getCommandName());
            $run->markFinished(false, $message);

            return ['jobName' => $job->getName(), 'sourceType' => 'db', 'success' => false, 'message' => $message];
        }

        $process = $this->cronCommandProcessFactory->create($job->getCommandName(), $job->getCommandArguments());
        $process->run();

        $job->markRunAt($now);
        $output = $process->getOutput().$process->getErrorOutput();
        $run->markFinished($process->isSuccessful(), $output);

        return [
            'jobName' => $job->getName(),
            'sourceType' => 'db',
            'success' => $process->isSuccessful(),
            'message' => $process->isSuccessful()
                ? sprintf('"%s" çalıştırıldı.', $job->getCommandName())
                : sprintf('"%s" başarısız oldu (exit code %d).', $job->getCommandName(), $process->getExitCode() ?? -1),
        ];
    }

    /**
     * @return array{jobName: string, sourceType: 'code', success: bool, message: string}
     */
    private function runVirtualJob(VirtualCronJob $job): array
    {
        $process = $this->cronCommandProcessFactory->create('cp:cron:run-virtual', $job->getJobName());
        $process->run();

        return [
            'jobName' => $job->getJobName(),
            'sourceType' => 'code',
            'success' => $process->isSuccessful(),
            'message' => $process->isSuccessful()
                ? '[KOD] çalıştırıldı.'
                : sprintf('[KOD] başarısız oldu (exit code %d).', $process->getExitCode() ?? -1),
        ];
    }
}
