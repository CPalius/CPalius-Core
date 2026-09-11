<?php

declare(strict_types=1);

namespace App\Core\Security;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only role → module → capability summary for AACP. Authorization stays in CPaliusVoter.
 */
final class RoleCapabilityPresenter
{
    public function __construct(
        private readonly RoleConfigManager $roleConfigManager,
        private readonly CapabilityRegistry $capabilityRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Catalog for the role picker and live preview.
     *
     * @return array<string, array{label: string, modules: array<string, list<string>>, fullAccess: bool}>
     */
    public function buildRoleCatalog(): array
    {
        $catalog = [];
        $totalCapabilities = \count($this->capabilityRegistry->all());

        foreach ($this->roleConfigManager->getAllRoleIds() as $roleId) {
            $capabilities = $this->roleConfigManager->getCapabilitiesForRole($roleId);
            $catalog[$roleId] = [
                'label' => $this->translator->trans($this->roleConfigManager->getLabel($roleId) ?? $roleId),
                'modules' => $this->groupByModule($capabilities),
                'fullAccess' => $totalCapabilities > 0 && \count($capabilities) >= $totalCapabilities,
            ];
        }

        return $catalog;
    }

    /**
     * @param list<string> $roleIds
     *
     * @return array{modules: array<string, list<string>>, fullAccess: bool}
     */
    public function summarizeSelectedRoles(array $roleIds): array
    {
        $roleIds = array_values(array_filter($roleIds, fn (string $id): bool => $this->roleConfigManager->hasRole($id)));
        $capabilities = $this->roleConfigManager->getCapabilitiesForRoles($roleIds);
        $totalCapabilities = \count($this->capabilityRegistry->all());

        return [
            'modules' => $this->groupByModule($capabilities),
            'fullAccess' => $totalCapabilities > 0 && \count($capabilities) >= $totalCapabilities,
        ];
    }

    /**
     * @param list<string> $capabilities
     *
     * @return array<string, list<string>>
     */
    private function groupByModule(array $capabilities): array
    {
        $grouped = [];

        foreach ($capabilities as $capability) {
            $moduleKey = $this->resolveModuleKey($this->capabilityRegistry->getSource($capability) ?? 'core');
            $grouped[$moduleKey][] = $capability;
        }

        ksort($grouped);
        foreach ($grouped as &$items) {
            sort($items);
        }
        unset($items);

        return $grouped;
    }

    private function resolveModuleKey(string $source): string
    {
        if ($source === 'core') {
            return 'core';
        }

        if (preg_match('/Modules\\\\([^\\\\]+)\\\\/', $source, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return 'core';
    }
}
