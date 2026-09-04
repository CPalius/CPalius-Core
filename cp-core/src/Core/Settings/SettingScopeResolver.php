<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Annotation\CpSetting;
use App\Core\Plugin\PluginRegistry;

/**
 * Decides which /aacp/settings tab a definition belongs to.
 * Explicit #[CpSetting(scope: ...)] always wins; otherwise the owning module id is used.
 */
final class SettingScopeResolver
{
    public function __construct(
        private readonly PluginRegistry $pluginRegistry,
    ) {
    }

    public function resolve(SettingDefinition $definition): string
    {
        if ($definition->hasExplicitScope()) {
            return (string) $definition->scope;
        }

        if ($definition->module === CpSetting::SCOPE_CORE) {
            return CpSetting::SCOPE_CORE;
        }

        // A module id registered in PluginRegistry identifies a plugin, not a module.
        return $this->pluginRegistry->getPlugin($definition->module) !== null
            ? CpSetting::SCOPE_PLUGIN
            : CpSetting::SCOPE_MODULE;
    }

    /**
     * Definitions of one scope, grouped by their $group, ready for the tab renderer.
     *
     * @param list<SettingDefinition> $definitions
     *
     * @return array<string, list<SettingDefinition>>
     */
    public function groupByScope(array $definitions, string $scope): array
    {
        $grouped = [];

        foreach ($definitions as $definition) {
            if ($definition->isHiddenFromSettingsScreen() || $this->resolve($definition) !== $scope) {
                continue;
            }

            $grouped[$definition->group][] = $definition;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Number of visible settings per scope, used for the tab badges.
     *
     * @param list<SettingDefinition> $definitions
     *
     * @return array<string, int>
     */
    public function countByScope(array $definitions): array
    {
        $counts = [
            CpSetting::SCOPE_CORE => 0,
            CpSetting::SCOPE_MODULE => 0,
            CpSetting::SCOPE_PLUGIN => 0,
        ];

        foreach ($definitions as $definition) {
            if ($definition->isHiddenFromSettingsScreen()) {
                continue;
            }

            ++$counts[$this->resolve($definition)];
        }

        return $counts;
    }
}
