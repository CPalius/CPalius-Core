<?php

declare(strict_types=1);

namespace App\Core\Cron;

/**
 * Restricts CronJob commands to "cp:*" (compile-time list). Vendor/Doctrine commands are excluded.
 * Re-checked at dispatch so a DB bypass still cannot run non-whitelisted names.
 */
final class CronCommandWhitelist
{
    /**
     * @param list<string> $allowedCommandNames "cp:" names from CronCommandRegistrationPass (cpalius.cron_allowed_commands).
     */
    public function __construct(
        private readonly array $allowedCommandNames,
    ) {
    }

    /**
     * @return list<string> Alphabetically sorted "cp:" command names.
     */
    public function allowedCommandNames(): array
    {
        return $this->allowedCommandNames;
    }

    public function isAllowed(string $commandName): bool
    {
        return \in_array($commandName, $this->allowedCommandNames, true);
    }
}
