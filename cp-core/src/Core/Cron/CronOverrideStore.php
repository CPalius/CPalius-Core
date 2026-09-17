<?php

declare(strict_types=1);

namespace App\Core\Cron;

use Doctrine\DBAL\Connection;

/**
 * Operator-chosen schedule and on/off state for the code-declared cron jobs.
 *
 * #[CpCronJob] bakes a schedule into the source, which is the right default —
 * the author of a task knows how often it needs to run — but a default is not a
 * decision. A site that wants the nightly purge at 05:00 instead of 03:15, or
 * wants it off for a week, had to edit PHP and lose the change on the next
 * update. The override row is that decision, kept outside the code it overrides.
 *
 * Deliberately DBAL rather than an ORM entity: this is read on every cron
 * dispatch and on the AACP list, one flat map with no relations, and keeping it
 * out of the entity manager keeps the dispatcher path free of a hydration it
 * would never use.
 */
final class CronOverrideStore
{
    /** @var array<string, array{schedule: ?string, active: bool}>|null Per-request memo. */
    private ?array $memo = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly CronOverrideSchema $schema,
    ) {
    }

    /**
     * Every override, keyed by job name.
     *
     * @return array<string, array{schedule: ?string, active: bool}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT job_name, cron_expression, active FROM cp_cron_overrides',
            );
        } catch (\Throwable) {
            // The table is created lazily, so "missing" is a normal first-run
            // state, not an error. No overrides means the shipped schedules.
            $this->schema->ensure();

            return $this->memo = [];
        }

        $overrides = [];

        foreach ($rows as $row) {
            $expression = trim((string) ($row['cron_expression'] ?? ''));

            $overrides[(string) $row['job_name']] = [
                'schedule' => $expression !== '' ? $expression : null,
                'active' => (bool) $row['active'],
            ];
        }

        return $this->memo = $overrides;
    }

    /**
     * @return array{schedule: ?string, active: bool}|null
     */
    public function find(string $jobName): ?array
    {
        return $this->all()[$jobName] ?? null;
    }

    /**
     * Stores one job's schedule and state. A null or empty $schedule means
     * "keep whatever the code says", so an operator who only wants to switch a
     * task off does not also freeze its schedule at today's value.
     */
    public function save(string $jobName, ?string $schedule, bool $active): void
    {
        $this->schema->ensure();

        $schedule = $schedule !== null && trim($schedule) !== '' ? trim($schedule) : null;

        $this->connection->executeStatement(
            'INSERT INTO cp_cron_overrides (job_name, cron_expression, active, updated_at)
             VALUES (:jobName, :schedule, :active, :now)
             ON DUPLICATE KEY UPDATE cron_expression = :schedule, active = :active, updated_at = :now',
            [
                'jobName' => $jobName,
                'schedule' => $schedule,
                'active' => $active ? 1 : 0,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $this->memo = null;
    }

    /**
     * Drops the override so the job goes back to its declared schedule.
     */
    public function forget(string $jobName): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM cp_cron_overrides WHERE job_name = :jobName',
                ['jobName' => $jobName],
            );
        } catch (\Throwable) {
            // Nothing to undo when the table was never created.
        }

        $this->memo = null;
    }
}
