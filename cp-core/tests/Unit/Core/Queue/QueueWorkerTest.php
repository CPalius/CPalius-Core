<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Queue;

use App\Core\Database\TenantContext;
use App\Core\Database\TenantScope;
use App\Core\Queue\AsyncJobHandlerInterface;
use App\Core\Queue\Entity\AsyncJob;
use App\Core\Queue\QueueWorker;
use App\Core\Queue\Repository\AsyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Core Never Dies: a throwing job handler must not escape the worker loop.
 */
#[CoversClass(QueueWorker::class)]
final class QueueWorkerTest extends TestCase
{
    public function testSuccessfulHandlerMarksJobDone(): void
    {
        $job = new AsyncJob('demo', ['x' => 1]);
        $worker = $this->worker([$job], [$this->handler('demo', static fn () => null)]);

        $stats = $worker->run();

        self::assertSame(1, $stats['processed']);
        self::assertTrue($job->isTerminal());
    }

    public function testThrowingHandlerIsContainedAndRetried(): void
    {
        $job = new AsyncJob('demo', ['x' => 1]);
        $worker = $this->worker([$job], [$this->handler('demo', static function (): void {
            throw new \RuntimeException('boom');
        })]);

        $stats = $worker->run();

        self::assertSame(0, $stats['processed']);
        self::assertSame(1, $stats['retried']);
        self::assertSame(1, $job->getAttempts());
        self::assertFalse($job->isTerminal());
    }

    public function testUnknownJobTypeIsRetriedNotFatal(): void
    {
        $job = new AsyncJob('no-such-type', []);
        $worker = $this->worker([$job], [$this->handler('demo', static fn () => null)]);

        $stats = $worker->run();

        self::assertSame(1, $stats['failed']);
        self::assertSame(1, $job->getAttempts());
    }

    public function testHandlerThatAlwaysThrowsEventuallyDeadLetters(): void
    {
        $job = new AsyncJob('demo', []);
        $handler = $this->handler('demo', static function (): void {
            throw new \RuntimeException('always');
        });

        for ($i = 0; $i < AsyncJob::MAX_ATTEMPTS; ++$i) {
            $this->worker([$job], [$handler])->run();
        }

        self::assertTrue($job->isTerminal());
        self::assertGreaterThanOrEqual(AsyncJob::MAX_ATTEMPTS, $job->getAttempts());
    }

    /**
     * @param list<AsyncJob>                 $due
     * @param list<AsyncJobHandlerInterface> $handlers
     */
    private function worker(array $due, array $handlers): QueueWorker
    {
        $repository = $this->createMock(AsyncJobRepository::class);
        $repository->method('claimDue')->willReturn($due);

        $em = $this->createMock(EntityManagerInterface::class);

        return new QueueWorker(
            $repository,
            $em,
            new TenantScope(new TenantContext(), $em),
            new NullLogger(),
            $handlers,
            sys_get_temp_dir(),
        );
    }

    private function handler(string $type, callable $run): AsyncJobHandlerInterface
    {
        return new class($type, $run) implements AsyncJobHandlerInterface {
            /** @param callable $run */
            public function __construct(private readonly string $type, private $run)
            {
            }

            public function supports(string $type): bool
            {
                return $type === $this->type;
            }

            public function handle(AsyncJob $job): void
            {
                ($this->run)($job);
            }
        };
    }
}
