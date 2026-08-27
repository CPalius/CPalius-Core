<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use App\Core\Menu\AdminMenuRegistry;
use App\Core\Menu\MenuItemDefinition;
use App\Core\Module\ModuleRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Sidebar'a sunulacak menü öğelerinin GERÇEK filtrelemesi burada,
 * HER render'da taze yapılır (bkz. AdminMenuRegistrationPass'in
 * derleme-zamanı toplama ile bilinçli olarak filtrelemeyi ayırma
 * gerekçesi): active_modules.php AACP üzerinden runtime'da cache
 * temizlemeden değişebilir, bu yüzden "modül aktif mi" sorusu asla
 * derleme zamanında sabitlenmemelidir.
 *
 * Security::isGranted(), firewall dışı/anonim bağlamlarda (ör. henüz
 * giriş yapılmamış bir istek) exception fırlatmak yerine güvenle false
 * döner — bu yüzden AACP recovery gibi bilinçli olarak "her zaman ayakta"
 * kalması gereken akışlarda bile bu servis çağrısı güvenlidir.
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
     * İki seviyeli sidebar ağacını kurar: $parent'ı NULL olan öğeler üst
     * seviye link olarak döner, $parent'ı dolu (bir başka öğenin
     * routeName'ine eşit) öğeler o üst öğenin "children" listesine gömülür.
     *
     * Bir öğenin $parent'ı, filtrelemeden ELENMİŞ (modül pasif veya
     * yetkisiz) bir routeName'e işaret ediyorsa, o alt öğe de sessizce
     * atlanır (yetim/orphan bırakılmaz) — aksi halde sidebar'da hiçbir
     * üst öğenin altına bağlanmayan başıboş bir link belirirdi.
     *
     * @return list<array{label: string, icon: string, routeName: string, routePrefix: string, group: ?string, children: list<array{label: string, icon: string, routeName: string, routePrefix: string}>}>
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

        return $topLevel;
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
