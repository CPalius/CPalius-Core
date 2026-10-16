<?php

declare(strict_types=1);

namespace Modules\Menu\Location;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module declares the menu locations it prints with {{ cp_menu_location('id') }}.
 * Themes declare theirs in theme.json under "menu_locations" instead.
 */
#[AutoconfigureTag('cpalius.menu_location')]
interface MenuLocationProviderInterface
{
    /**
     * @return array<string, string> location id (a-z, 0-9, underscore) => label or translation key
     */
    public function locations(): array;
}
