<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Database\TenantScope;
use App\Core\Queue\Entity\AsyncJob;
use App\Core\Queue\Repository\AsyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Claims due jobs and runs matching handlers in isolation. A throwing handler retries with backoff;
 * the worker process itself never 500s the kernel.
 */
final class QueueWorker
{
    /**
     * @param iterable<AsyncJobHandlerInterface> $handlers
     */
    public function __construct(
        private readonly AsyncJobRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantScope $tenantScope,
        private readonly LoggerInterface $logger,
        private readonly iterable $handlers,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{processed: int, failed: int, retried: int}
     */
    public function run(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $stats = ['processed' => 0, 'failed' => 0, 'retried' => 0];
        $jobs = $this->repository->claimDue($limit, new \DateTimeImmutable());

        foreach ($jobs as $job) {
            $this->runOne($job, $stats);
        }

        return $stats;
    }

    /**
     * @param array{processed: int, failed: int, retried: int} $stats
     */
    private function runOne(AsyncJob $job, array &$stats): void
    {
        $handler = $this->handlerFor($job->getType());
        if ($handler === null) {
            $job->markRetry('No handler for job type.', $this->backoff($job->getAttempts() + 1));
            $this->entityManager->flush();
            ++$stats['failed'];

            return;
        }

        $this->tenantScope->applyOptional($job->getTenantId());

        try {
            $handler->handle($job);
            $job->markDone();
            ++$stats['processed'];
        } catch (\Throwable $e) {
            $this->quarantine($job, $e);
            $job->markRetry($e->getMessage(), $this->backoff($job->getAttempts() + 1));
            if ($job->isTerminal()) {
                ++$stats['failed'];
            } else {
                ++$stats['retried'];
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Queue worker could not persist job state.', ['exception' => $e->getMessage()]);
        }
    }

    private function handlerFor(string $type): ?AsyncJobHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                return $handler;
            }
        }

        return null;
    }

    private function backoff(int $attempt): \DateTimeImmutable
    {
        $seconds = min(3600, 4 ** max(1, $attempt));

        return (new \DateTimeImmutable())->modify('+'.$seconds.' seconds');
    }

    private function quarantine(AsyncJob $job, \Throwable $e): void
    {
        $this->logger->error('Async job handler failed.', [
            'type' => $job->getType(),
            'job' => $job->getId(),
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] async job #%s type=%s skipped: %s',
            date('Y-m-d H:i:s'),
            (string) $job->getId(),
            $job->getType(),
            $e->getMessage(),
        );
        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
