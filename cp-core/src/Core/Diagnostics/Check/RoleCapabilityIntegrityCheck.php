<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Check;

use App\Core\Diagnostics\DoctorCheckInterface;
use App\Core\Diagnostics\DoctorFinding;
use App\Core\Security\CapabilityRegistry;
use App\Core\Security\RoleConfigManager;

/**
 * Cross-checks the role configuration against the capability registry.
 *
 * CBAC is unknown-deny, which makes the system safe but silent: a capability
 * misspelled in a role YAML file does not raise anything, it simply never
 * grants. The operator sees "the editor cannot publish" and has no way to tell
 * a deliberate restriction from a typo. This check turns that silence into a
 * message.
 *
 * The opposite direction is reported too, at low severity: a registered
 * capability that no role holds is usually either a feature nobody can reach
 * yet, or a leftover from a removed module.
 */
final class RoleCapabilityIntegrityCheck implements DoctorCheckInterface
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly RoleConfigManager $roles,
    ) {
    }

    public function key(): string
    {
        return 'capabilities';
    }

    public function run(): array
    {
        $registered = $this->capabilities->all();
        $granted = [];
        $unknown = [];

        foreach ($this->roles->getAllRoleIds() as $roleId) {
            foreach ($this->roles->getCapabilitiesForRole($roleId) as $capability) {
                // The wildcard is expanded by RoleConfigManager against the
                // registry, so it can never be an unknown name.
                if ($capability === '*') {
                    continue;
                }

                $granted[$capability] = true;

                if (!$this->capabilities->has($capability)) {
                    $unknown[$capability][] = $roleId;
                }
            }
        }

        $findings = [];

        foreach ($unknown as $capability => $roleIds) {
            $findings[] = new DoctorFinding(
                id: 'capabilities.unknown_in_role',
                severity: DoctorFinding::SEVERITY_MEDIUM,
                title: 'Role grants a capability that is not registered',
                detail: sprintf(
                    '"%s" is granted by role(s) %s but no module registers it; unknown-deny means the grant does nothing.',
                    $capability,
                    implode(', ', $roleIds),
                ),
                remedy: 'Correct the spelling in the role config, or remove the grant if the module was uninstalled.',
            );
        }

        $unused = array_values(array_diff($registered, array_keys($granted)));

        if ($unused !== []) {
            $findings[] = new DoctorFinding(
                id: 'capabilities.unused',
                severity: DoctorFinding::SEVERITY_LOW,
                title: 'Registered capabilities that no role grants',
                detail: sprintf(
                    '%d capability/capabilities are unreachable: %s',
                    \count($unused),
                    implode(', ', \array_slice($unused, 0, 10)).(\count($unused) > 10 ? ' …' : ''),
                ),
                remedy: 'Expected for features still being built; otherwise a role is missing a grant.',
            );
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::pass(
                'capabilities.unknown_in_role',
                'Capabilities',
                sprintf('%d registered capabilities, every role grant resolves.', \count($registered)),
            );
        }

        return $findings;
    }
}
