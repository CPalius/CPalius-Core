<?php

declare(strict_types=1);

namespace App\Core\Menu\Twig;

use App\Entity\Menu;
use App\Entity\MenuItem;
use App\Entity\Node;
use App\Repository\MenuItemRepository;
use App\Repository\MenuRepository;
use App\Repository\NodeRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * {{ cp_menu('header') }} çağrısının veri kaynağı. Bilinçli olarak HTML
 * ÜRETMEZ, sadece düz bir dizi döner — <nav>/<ul> markup'ı temaya aittir,
 * böylece her tema kendi görsel diline uygun bir yapı bastırabilir.
 *
 * MenuAdminController'ın create/update/delete işlemleri, ilgili menü+locale
 * kombinasyonunun cache anahtarını invalidate eder (bkz. o controller).
 */
final class FrontMenuRuntime implements RuntimeExtensionInterface
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly MenuRepository $menuRepository,
        private readonly MenuItemRepository $menuItemRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CacheInterface $cacheApp,
    ) {
    }

    /**
     * @return list<array{label: string, url: string, openInNewTab: bool, children: array}>
     */
    public function render(string $identifier, string $locale = 'tr'): array
    {
        return $this->cacheApp->get(
            self::cacheKey($identifier, $locale),
            function (ItemInterface $item) use ($identifier, $locale): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                $menu = $this->menuRepository->findOneByIdentifier($identifier);
                if ($menu === null) {
                    return [];
                }

                // TÜM öğeler (her seviye) ve ilişkili Node'lar İKİ sorguda
                // (menu_items + nodes) çekilir, ağaç bellek içinde kurulur.
                // Önceki hâl her menü seviyesi + her Node için ayrı sorgu
                // atıyordu ("menu_items" tablosuna 11+ sorgu, dev-mode N+1
                // guard'ını tetikliyordu — bkz. bu sınıfın docblock'u).
                $items = $this->menuItemRepository->findAllByMenuAndLocale($menu, $locale);

                $nodeIds = [];
                foreach ($items as $menuItem) {
                    if ($menuItem->getNodeId() !== null) {
                        $nodeIds[] = $menuItem->getNodeId();
                    }
                }
                $nodesById = $this->nodeRepository->findByIdsIndexed(array_values(array_unique($nodeIds)));

                $childrenByParentId = [];
                foreach ($items as $menuItem) {
                    $parentId = $menuItem->getParent()?->getId() ?? 0;
                    $childrenByParentId[$parentId][] = $menuItem;
                }

                return $this->buildTree($childrenByParentId, $nodesById, null);
            },
        );
    }

    public static function cacheKey(string $identifier, string $locale): string
    {
        return sprintf('cp_menu.%s.%s', $identifier, $locale);
    }

    /**
     * @param array<int, list<MenuItem>> $childrenByParentId
     * @param array<int, Node>           $nodesById
     *
     * @return list<array{label: string, url: string, openInNewTab: bool, children: array}>
     */
    private function buildTree(array $childrenByParentId, array $nodesById, ?int $parentId): array
    {
        $tree = [];

        foreach ($childrenByParentId[$parentId ?? 0] ?? [] as $item) {
            $url = $this->resolveUrl($item, $nodesById);
            if ($url === null) {
                // Node silinmiş/yayından kalkmış: bu öğe sessizce atlanır
                // (fail-safe), menü kırılmaz.
                continue;
            }

            $tree[] = [
                'label' => $item->getLabel(),
                'url' => $url,
                'openInNewTab' => $item->isOpenInNewTab(),
                'children' => $this->buildTree($childrenByParentId, $nodesById, $item->getId()),
            ];
        }

        return $tree;
    }

    /**
     * @param array<int, Node> $nodesById
     */
    private function resolveUrl(MenuItem $item, array $nodesById): ?string
    {
        if ($item->getUrl() !== null) {
            return $item->getUrl();
        }

        if ($item->getNodeId() === null) {
            return null;
        }

        $node = $nodesById[$item->getNodeId()] ?? null;
        if ($node === null || $node->getDeletedAt() !== null || $node->getStatus() !== Node::STATUS_PUBLISHED) {
            return null;
        }

        return sprintf('/%s/%s', $node->getLocale(), $node->getSlug());
    }
}
