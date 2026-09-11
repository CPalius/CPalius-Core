<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Update\UpdateHookInterface;
use App\Core\Update\UpdateRunner;

/**
 * Reports work that cp:update would do.
 *
 * The two commands are deliberately one pair: cp:doctor notices that an
 * installation is behind, cp:update brings it forward. Without this check, a
 * site whose files were updated but whose database never was would report
 * perfectly healthy — which is exactly the failure that motivated cp:doctor in
 * the first place, one layer up.
 *
 * Migrations are covered by their own check, so this one deliberately reports
 * only the stages that check cannot see: pending update hooks and modules whose
 * manifest version has moved ahead of what is installed.
 */
final class PendingUpdatesCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly UpdateRunner $runner,
    ) {
    }

    public function key(): string
    {
        return 'updates';
    }

    public function run(): array
    {
        $pendingHooks = $this->runner->pendingHooks();

        $findings = [];

        if ($pendingHooks !== []) {
            $findings[] = new DoctorFinding(
                id: 'updates.pending_hooks',
                // High for the same reason an unapplied migration is: the data
                // fix is not merely late, its absence is invisible.
                severity: DoctorFinding::SEVERITY_HIGH,
                title: 'Update hooks are waiting to run',
                detail: sprintf(
                    '%d pending: %s',
                    \count($pendingHooks),
                    implode(', ', array_map(
                        static fn (UpdateHookInterface $hook): string => $hook->id(),
                        \array_slice($pendingHooks, 0, 5),
                    )),
                ),
                remedy: 'php cp-core/bin/console cp:update',
            );
        }

        // A dry run answers the module question without changing anything,
        // which is the only thing a diagnostic is allowed to do.
        foreach ($this->runner->run(dryRun: true) as $step) {
            if ($step->step !== UpdateRunner::STEP_MODULES || !$step->changedAnything()) {
                continue;
            }

            $findings[] = new DoctorFinding(
                id: 'updates.pending_module_upgrades',
                severity: DoctorFinding::SEVERITY_MEDIUM,
                title: 'Modules are behind their manifest version',
                detail: implode('; ', $step->details),
                remedy: 'php cp-core/bin/console cp:update',
            );
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::pass(
                'updates.pending_hooks',
                'Updates',
                'No update hooks or module upgrades are pending.',
            );
        }

        return $findings;
    }
}
