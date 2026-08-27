<?php

declare(strict_types=1);

namespace App\Core\Menu;

/**
 * #[CpAdminMenu] ile işaretlenmiş TÜM action'ların tek doğruluk kaynağı.
 *
 * ResourceRegistry ile aynı ayrım: bu sınıf hiçbir tarama yapmaz, sadece
 * AdminMenuRegistrationPass tarafından derleme zamanında doldurulan pasif
 * bir depodur. Modül aktiflik / yetki filtrelemesi burada YAPILMAZ —
 * bu registry aktif olmayan modüllerin öğelerini de içerir, gerçek
 * filtreleme render zamanında AdminMenuRuntime içinde yapılır.
 */
final class AdminMenuRegistry
{
    /** @var array<string, list<MenuItemDefinition>> panel => öğeler */
    private array $byPanel = [];

    public function add(MenuItemDefinition $item): void
    {
        $this->byPanel[$item->panel][] = $item;
    }

    /**
     * @return list<MenuItemDefinition> priority'ye göre artan sırada
     *   (eşitlik durumunda ekleme sırası korunur — PHP'nin stabil sort'u).
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
