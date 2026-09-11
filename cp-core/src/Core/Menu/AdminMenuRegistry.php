<?php

declare(strict_types=1);

namespace App\Core\Menu;

/**
 * Single source of truth for all #[CpAdminMenu] actions; passive store filled by AdminMenuRegistrationPass.
 * No scanning or filtering here — AdminMenuRuntime filters by module/capability at render time.
 */
final class AdminMenuRegistry
{
    /** @var array<string, list<MenuItemDefinition>> panel => items */
    private array $byPanel = [];

    public function add(MenuItemDefinition $item): void
    {
        $this->byPanel[$item->panel][] = $item;
    }

    /**
     * @return list<MenuItemDefinition> sorted by ascending priority (stable sort preserves insertion order on ties)
     */
    public function byPanel(string $panel): array
    {
        $items = $this->byPanel[$panel] ?? [];

        usort($items, static fn (MenuItemDefinition $a, MenuItemDefinition $b): int => $a->priority <=> $b->priority);

        return $items;
    }

    /**
     * @return list<MenuItemDefinition>
     */
    public function all(): array
    {
        return array_merge(...array_values($this->byPanel === [] ? [[]] : $this->byPanel));
    }
}
