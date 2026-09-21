<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use App\Core\Admin\StudioShell;
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
        private readonly StudioShell $studioShell,
    ) {
    }

    /**
     * What the Studio layout needs to know about who owns the panel.
     *
     * Returned as a plain array rather than the service, so a template cannot
     * reach past the three values it is entitled to.
     *
     * @return array{active: bool, brandText: ?string, brandKey: ?string, subtitleKey: ?string, homeRoute: ?string, logoUrl: ?string}
     */
    public function studioShell(): array
    {
        $brand = $this->studioShell->brand();

        return [
            'active' => $this->studioShell->isActive(),
            'brandText' => $brand['text'],
            'brandKey' => $brand['key'],
            'subtitleKey' => $this->studioShell->subtitleKey(),
            'homeRoute' => $this->studioShell->homeRoute(),
            'logoUrl' => $this->studioShell->logoUrl(),
        ];
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

            // A module that has claimed Studio reduces the menu to itself plus
            // whatever it declared it still needs. Applied here rather than in
            // the layout so the tree is built from what survives: a parent whose
            // children were all dropped is a dead link, and one kept without its
            // children is a submenu that lost its contents.
            //
            // Which is why keeping a parent keeps its children. A module saying
            // it still needs the pages screen means the pages screen, not its
            // landing page with the three things it does missing.
            if ($panel === 'studio'
                && !$this->studioShell->allows($item->module, $item->routeName)
                && ($item->parent === null || !$this->studioShell->allows($item->module, $item->parent))
            ) {
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
                // A shell owner may file a screen it kept under one of its own
                // headings, so the menu reads as one panel rather than as a
                // module bolted onto a content workspace.
                'group' => ($panel === 'studio' ? $this->studioShell->groupFor($item->routeName) : null)
                    ?? $item->group,
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
