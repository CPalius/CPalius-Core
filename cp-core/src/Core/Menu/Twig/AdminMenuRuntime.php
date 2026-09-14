<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use App\Core\Menu\AdminMenuRegistry;
use App\Core\Menu\MenuItemDefinition;
use App\Core\Module\ModuleRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Filters sidebar menu items on every render (active_modules.php can change at runtime without cache clear).
 * Security::isGranted() returns false safely for anonymous contexts — safe for AACP recovery flows.
 */
final class AdminMenuRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly AdminMenuRegistry $registry,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly Security $security,
    ) {
    }

    /**
     * Builds a two-level sidebar tree; children attach to visible parents; orphans are skipped.
     *
     * @return list<array{label: string, icon: string, routeName: string, routePrefix: string, group: ?string, hub: bool, children: list<array{label: string, icon: string, routeName: string, routePrefix: string}>}>
     */
    public function render(string $panel): array
    {
        $activeModuleClasses = array_column(
            array_filter(
                $this->moduleRegistry->discoverAllModules(),
                static fn (array $m): bool => $m['status'] === 'active',
            ),
            'class',
        );

        $visibleByRouteName = [];
        foreach ($this->registry->byPanel($panel) as $item) {
            if (!$this->isModuleActive($item, $activeModuleClasses)) {
                continue;
            }

            if (!$this->hasCapability($item)) {
                continue;
            }

            $visibleByRouteName[$item->routeName] = $item;
        }

        $topLevel = [];
        foreach ($visibleByRouteName as $item) {
            if ($item->parent !== null) {
                continue;
            }

            $topLevel[] = [
                'label' => $item->label,
                'icon' => $item->icon,
                'routeName' => $item->routeName,
                'routePrefix' => $item->routePrefix,
                'group' => $item->group,
                'hub' => str_starts_with($item->routeName, 'aacp_hub_'),
                'children' => [],
            ];
        }

        foreach ($visibleByRouteName as $item) {
            if ($item->parent === null || !isset($visibleByRouteName[$item->parent])) {
                continue;
            }

            foreach ($topLevel as &$parentEntry) {
                if ($parentEntry['routeName'] === $item->parent) {
                    $parentEntry['children'][] = [
                        'label' => $item->label,
                        'icon' => $item->icon,
                        'routeName' => $item->routeName,
                        'routePrefix' => $item->routePrefix,
                    ];
                    break;
                }
            }
            unset($parentEntry);
        }

        return array_values(array_filter(
            $topLevel,
            static fn (array $item): bool => $item['hub'] === false || $item['children'] !== [],
        ));
    }

    /**
     * @param list<string> $activeModuleClasses
     */
    private function isModuleActive(MenuItemDefinition $item, array $activeModuleClasses): bool
    {
        return $item->module === 'core' || in_array($item->module, $activeModuleClasses, true);
    }

    private function hasCapability(MenuItemDefinition $item): bool
    {
        if ($item->capability === null) {
            return true;
        }

        foreach (explode('|', $item->capability) as $capability) {
            if ($this->security->isGranted($capability)) {
                return true;
            }
        }

        return false;
    }
}
