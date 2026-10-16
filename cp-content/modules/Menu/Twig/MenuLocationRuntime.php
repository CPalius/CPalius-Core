<?php

declare(strict_types=1);

namespace Modules\Menu\Twig;

use Modules\Menu\Repository\MenuRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * {{ cp_menu_location('main_header') }} prints the menu assigned to that location;
 * cp_menu_location_items() returns the same tree for themes that bring their own markup.
 */
final class MenuLocationRuntime implements RuntimeExtensionInterface
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly MenuRepository $menus,
        private readonly FrontMenuRuntime $frontMenu,
        private readonly CacheInterface $cacheApp,
        private readonly Environment $twig,
    ) {
    }

    public static function cacheKey(string $location): string
    {
        return 'cp_menu.location.v1.'.$location;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $location, ?string $locale = null): array
    {
        if (preg_match('/^[a-z0-9_]{1,60}$/', $location) !== 1) {
            return [];
        }

        $identifier = $this->cacheApp->get(self::cacheKey($location), function (ItemInterface $item) use ($location): string {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->menus->findOneByLocation($location)?->getIdentifier() ?? '';
        });

        return $identifier === '' ? [] : $this->frontMenu->render($identifier, $locale);
    }

    public function render(string $location, ?string $locale = null, string $template = '@MenuModule/front/menu.html.twig'): string
    {
        $items = $this->items($location, $locale);
        if ($items === []) {
            return '';
        }

        return $this->twig->render($template, ['location' => $location, 'items' => $items]);
    }
}
