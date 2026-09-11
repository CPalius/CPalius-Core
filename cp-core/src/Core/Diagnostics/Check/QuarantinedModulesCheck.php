<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Module\ModuleRegistry;

/**
 * Reports modules the kernel isolated during boot.
 *
 * "Core Never Dies" means a broken module is quarantined and the site stays
 * up — which is the right behaviour, and also the reason this check has to
 * exist. The failure is deliberately invisible to visitors, so without
 * something that asks the question out loud, a module can stay switched off
 * for weeks while everyone assumes it is running.
 */
final class QuarantinedModulesCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly ModuleRegistry $modules,
    ) {
    }

    public function key(): string
    {
        return 'modules';
    }

    public function run(): array
    {
        $quarantined = $this->modules->getQuarantinedModules();

        if ($quarantined === []) {
            return [DoctorFinding::pass(
                'modules.quarantined',
                'Module isolation',
                'No module was quarantined during boot.',
            )];
        }

        $findings = [];

        foreach ($quarantined as $module) {
            $findings[] = new DoctorFinding(
                id: 'modules.quarantined',
                // High rather than critical: the site is serving traffic, but a
                // feature the operator believes is live is silently absent.
                severity: DoctorFinding::SEVERITY_HIGH,
                title: 'Module is quarantined',
                detail: sprintf('%s — %s', $module['class'], $module['reason']),
                remedy: 'Fix the error, then re-activate with cp:module:activate.',
            );
        }

        return $findings;
    }
}
