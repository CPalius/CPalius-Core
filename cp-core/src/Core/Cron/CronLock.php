<?php

declare(strict_types=1);

namespace App\Core\Cron;

use Psr\Log\LoggerInterface;

/**
 * Stops a scheduled job from running while the previous run of the same job is
 * still going.
 *
 * Nothing prevented this before. A host calling /cron/execute every minute while
 * a ten-minute sweep is running started ten sweeps; a job that got slower as a
 * site grew quietly began overlapping itself. For a session sweep that only
 * wastes queries. For anything that moves money, sends mail or provisions a
 * server, an overlap is a duplicate — and the second run has no way of knowing
 * the first is mid-flight.
 *
 * Implemented with flock() rather than a cache key or a database row, for one
 * property that matters more than the rest here: the operating system releases
 * an flock when the process holding it dies. A lock with a time-to-live has to
 * guess how long the job might take, and guessing short releases the lock under
 * a job that is still running while guessing long means one crashed nightly
 * billing run blocks the next one for a whole day. There is nothing to expire
 * and nothing to clean up.
 *
 * The trade-off is scope: flock coordinates processes on one machine. That is
 * what a CPalius installation is — one document root, one PHP pool. A
 * deployment spreading cron across several application servers needs a shared
 * lock instead, and should take one in its own job rather than assume this
 * class covers it.
 *
 * When the lock file cannot be opened at all, the job runs anyway and the
 * failure is logged. An installation whose var/ directory is unwritable is
 * already broken, and refusing to run every scheduled task on top of that turns
 * a permissions problem into a site that silently stops doing anything.
 */
final class CronLock
{
    /**
     * Runtime state, so it belongs beside the cache and the logs rather than at
     * the repository root — cp-core/var is already gitignored and already wiped
     * when a release package is built.
     */
    private const DIRECTORY = 'cp-core/var/cron-locks';

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs $work while holding the named lock.
     *
     * @template T
     *
     * @param callable():T $work  what to do when the lock is ours
     * @param callable():T $onBusy what to return when somebody else holds it
     *
     * @return T
     */
    public function withLock(string $name, callable $work, callable $onBusy): mixed
    {
        $handle = $this->open($name);

        if ($handle === null) {
            // Cannot lock at all: run rather than stop the site's automation.
            return $work();
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return $onBusy();
        }

        try {
            return $work();
        } finally {
            // Order matters: release before closing, so the handle is still
            // valid when the lock is given up.
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Whether the named lock is currently held by somebody else.
     *
     * Only honest as a report — by the time a caller acts on it the answer may
     * have changed. Use withLock() to actually take the lock; this exists so a
     * screen can say "still running" without pretending to reserve anything.
     */
    public function isHeld(string $name): bool
    {
        $handle = $this->open($name);

        if ($handle === null) {
            return false;
        }

        try {
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                return true;
            }

            flock($handle, \LOCK_UN);

            return false;
        } finally {
            fclose($handle);
        }
    }

    public static function jobLockName(string $jobName): string
    {
        return 'job.'.$jobName;
    }

    /**
     * @return resource|null
     */
    private function open(string $name)
    {
        $directory = $this->projectDir.'/'.self::DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $this->logger->warning('Cron lock directory could not be created; jobs run without overlap protection.', [
                'directory' => $directory,
            ]);

            return null;
        }

        // The name reaches a filesystem path, and job names come from module
        // code and from the database. Hash rather than sanitise: no escaping
        // rule to get wrong, and two different names can never collide on a
        // case-insensitive filesystem.
        $path = $directory.'/'.hash('sha256', $name).'.lock';

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            $this->logger->warning('Cron lock file could not be opened; this job runs without overlap protection.', [
                'job' => $name,
                'path' => $path,
            ]);

            return null;
        }

        return $handle;
    }
}
