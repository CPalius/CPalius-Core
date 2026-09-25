<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Module\ModuleActivator;
use App\Core\Module\ModuleRegistry;

/**
 * Every site that had 2.2.20 was already counting page views — the VisitorStats
 * module didn't exist yet, it was core. 2.2.21 moves that code into an
 * optional module (see App\Core\Analytics\VisitorRecorderInterface) so a
 * CPalius core with no public site doesn't carry it — but a module a site
 * has never seen ships deactivated by default (ModuleActivator::activate()
 * is a deliberate admin action, never automatic just because new module
 * files arrived in an update). Without this hook, every existing site would
 * silently stop counting visitors the moment it applied this update. A
 * fresh install made no such promise, so it is unaffected either way.
 */
final class ActivateVisitorStatsModuleHook implements UpdateHookInterface
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ModuleActivator $moduleActivator,
    ) {
    }

    public function id(): string
    {
        return 'core.2_2_21.activate_visitor_stats_module';
    }

    public function version(): string
    {
        return '2.2.21';
    }

    public function description(): string
    {
        return 'Activates the new VisitorStats module so existing sites keep counting page views after it was split out of core.';
    }

    public function run(): ?string
    {
        foreach ($this->moduleRegistry->discoverAllModules() as $module) {
            if ($module['dirName'] !== 'VisitorStats') {
                continue;
            }

            if ($module['status'] === 'active') {
                return null;
            }

            $result = $this->moduleActivator->activate('VisitorStats');

            return $result['success']
                ? 'activated VisitorStats'
                : 'could not activate VisitorStats: '.$result['message'];
        }

        // Module files are not on disk (a build that never bundled it, or a
        // partial/corrupted release archive) — nothing to activate, and
        // nothing to fail loudly over; the next release still finds it.
        return null;
    }
}
