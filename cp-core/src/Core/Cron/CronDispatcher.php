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
    private const SWEEP_LOCK = 'dispatcher';

    public function __construct(
        private readonly CronManager $cronManager,
        private readonly CronExpressionEvaluator $cronExpressionEvaluator,
        private readonly CronCommandWhitelist $cronCommandWhitelist,
        private readonly CronCommandProcessFactory $cronCommandProcessFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly CronLock $lock,
    ) {
    }

    /**
     * Run every due task and return one result row per job for CLI/HTTP formatting.
     *
     * @return list<array{jobName: string, sourceType: 'db'|'code', success: bool, message: string}>
     */
    public function runDueTasks(): array
    {
        // One sweep at a time. Without this, a host that calls /cron/execute
        // every minute while a slow sweep is running starts a new sweep every
        // minute, and they pile up on the same database.
        //
        // Code jobs are not locked here as well — CronManager::runVirtualTask()
        // owns their lock, and taking it in this process too would deadlock the
        // subprocess it spawns.
        return $this->lock->withLock(
            self::SWEEP_LOCK,
            fn (): array => $this->runDueTasksUnlocked(),
            static fn (): array => [[
                'jobName' => 'cron.dispatcher',
                'sourceType' => 'code',
                'success' => true,
                'message' => 'Skipped: a previous cron run is still in progress.',
            ]],
        );
    }

    /**
     * @return list<array{jobName: string, sourceType: 'db'|'code', success: bool, message: string}>
     */
    private function runDueTasksUnlocked(): array
    {
        $now = new \DateTimeImmutable();

        $dueTasks = array_filter(
            $this->cronManager->getTasks(),
            function (CronJob|VirtualCronJob $task) use ($now): bool {
                // Both tracks answer isActive() now: a code job switched off in
                // AACP must stay off here too, or the panel would be lying.
                if (!$task->isActive()) {
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
        // A database job runs an arbitrary console command in a subprocess, so
        // there is no inner method to lock the way code jobs have. The lock has
        // to sit around the spawn, here and at the Run Now button.
        return $this->lock->withLock(
            CronLock::jobLockName($job->getName()),
            fn (): array => $this->spawnDatabaseJob($job, $now),
            static fn (): array => [
                'jobName' => $job->getName(),
                'sourceType' => 'db',
                'success' => true,
                'message' => sprintf('Skipped: "%s" is already running.', $job->getName()),
            ],
        );
    }

    /**
     * @return array{jobName: string, sourceType: 'db', success: bool, message: string}
     */
    private function spawnDatabaseJob(CronJob $job, \DateTimeImmutable $now): array
    {
        $run = new CronJobRun($job, triggeredManually: false);
        $this->entityManager->persist($run);

        if (!$this->cronCommandWhitelist->isAllowed($job->getCommandName())) {
            $message = sprintf('"%s" is not in the allowed (whitelist) commands and was not executed.', $job->getCommandName());
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
                ? sprintf('"%s" executed.', $job->getCommandName())
                : sprintf('"%s" failed (exit code %d).', $job->getCommandName(), $process->getExitCode() ?? -1),
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
                ? '[CODE] executed.'
                : sprintf('[CODE] failed (exit code %d).', $process->getExitCode() ?? -1),
        ];
    }
}
