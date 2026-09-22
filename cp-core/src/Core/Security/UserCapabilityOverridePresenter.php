<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;

/**
 * Accordion view-model for AACP. Does not authorize — CPaliusVoter does.
 */
final class UserCapabilityOverridePresenter
{
    public function __construct(
        private readonly RoleCapabilityPresenter $roles,
        private readonly UserCapabilityOverrideStore $store,
        private readonly UserCapabilityOverridePolicy $policy,
        private readonly RoleConfigManager $roleConfig,
    ) {
    }

    /**
     * @return array{
     *     groups: list<array{module: string, rows: list<array{capability: string, effect: string, roleHas: bool, grantable: bool, deniable: bool, overridden: bool}>, overrideCount: int}>,
     *     overrideCount: int
     * }
     */
    public function build(User $target, ?User $actor): array
    {
        $overlay = $this->store->forUser($target->getId());
        $roleCaps = array_fill_keys(
            $this->roleConfig->getCapabilitiesForRoles($target->getCpaliusRoles()),
            true,
        );

        $groups = [];
        $total = 0;

        foreach ($this->roles->allGrouped() as $module => $capabilities) {
            $rows = [];
            $count = 0;

            foreach ($capabilities as $capability) {
                $effect = $overlay[$capability] ?? UserCapabilityOverridePolicy::EFFECT_INHERIT;
                $overridden = $effect !== UserCapabilityOverridePolicy::EFFECT_INHERIT;
                if ($overridden) {
                    ++$count;
                    ++$total;
                }

                $rows[] = [
                    'capability' => $capability,
                    'effect' => $effect,
                    'roleHas' => isset($roleCaps[$capability]),
                    'grantable' => $this->policy->canGrant($capability),
                    'deniable' => $this->policy->canDeny($capability, $target, $actor),
                    'overridden' => $overridden,
                ];
            }

            $groups[] = [
                'module' => $module,
                'rows' => $rows,
                'overrideCount' => $count,
            ];
        }

        return [
            'groups' => $groups,
            'overrideCount' => $total,
        ];
    }
}
