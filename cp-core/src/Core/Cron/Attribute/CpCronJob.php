<?php

declare(strict_types=1);

namespace App\Core\Cron\Attribute;

/**
 * Binds a service method to a virtual cron job (no cp_cron_jobs row). Not repeatable: one schedule per method.
 * Collected by CronRegistrationPass; use this when the job needs DI instead of a flat-file hook.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CpCronJob
{
    /**
     * @param string $schedule Standard 5-field cron (same syntax as CronJob.cronExpression).
     * @param string $name     Unique job id (separate namespace from CronJob.name).
     * @param string $description Short text for AACP and CLI.
     */
    public function __construct(
        public readonly string $schedule,
        public readonly string $name,
        public readonly string $description = '',
    ) {
    }
}
