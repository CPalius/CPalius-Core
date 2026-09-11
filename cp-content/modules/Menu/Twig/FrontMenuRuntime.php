<?php

declare(strict_types=1);

namespace Modules\Menu\Twig;

use App\Core\Localization\LocaleProvider;
use App\Core\Module\ModuleContributionCatalog;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Modules\Menu\Entity\MenuItem;
use Modules\Menu\Repository\MenuItemRepository;
use Modules\Menu\Repository\MenuRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Data source for {{ cp_menu('header') }}. Locale comes from the request when omitted.
 */
final class FrontMenuRuntime implements RuntimeExtensionInterface
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly MenuRepository $menuRepository,
        private readonly MenuItemRepository $menuItemRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CacheInterface $cacheApp,
        private readonly RequestStack $requestStack,
        private readonly LocaleProvider $localeProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ModuleContributionCatalog $contributions,
    ) {
    }

    /**
     * @return list<array{label: string, url: string, openInNewTab: bool, children: array}>
     */
    public function render(string $identifier, ?string $locale = null): array
    {
        $locale = $this->resolveLocale($locale);

        return $this->cacheApp->get(
            self::cacheKey($identifier, $locale),
            function (ItemInterface $item) use ($identifier, $locale): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                $menu = $this->menuRepository->findOneByIdentifier($identifier);
                if ($menu === null) {
                    return [];
                }

                $allItems = $this->menuItemRepository->findAllByMenu($menu);
                $items = [];
                $idsInLocale = [];
                foreach ($allItems as $menuItem) {
                    if ($menuItem->getLocale() !== $locale) {
                        continue;
                    }
                    $items[] = $menuItem;
                    $id = $menuItem->getId();
                    if ($id !== null) {
                        $idsInLocale[$id] = true;
                    }
                }

                $nodeIds = [];
                foreach ($items as $menuItem) {
                    if ($menuItem->getNodeId() !== null) {
                        $nodeIds[] = $menuItem->getNodeId();
                    }
                }
                $nodesById = $this->nodeRepository->findByIdsIndexed(array_values(array_unique($nodeIds)));

                $childrenByParentId = [];
                foreach ($items as $menuItem) {
                    $parentId = $this->parentIdInLocale($menuItem, $locale, $idsInLocale, $allItems);
                    $childrenByParentId[$parentId][] = $menuItem;
                }

                return $this->buildTree($childrenByParentId, $nodesById, $allItems, $locale, null);
            },
        );
    }

    public static function cacheKey(string $identifier, string $locale): string
    {
        return sprintf('cp_menu.%s.%s', $identifier, $locale);
    }

    private function resolveLocale(?string $locale): string
    {
        if ($locale !== null && $locale !== '') {
            return $this->localeProvider->resolve($locale);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            return $this->localeProvider->resolve($request->getLocale());
        }

        return $this->localeProvider->getDefaultCode();
    }

    /**
     * Attach under this locale's parent row. A parent from another locale is remapped via translation group.
     *
     * @param array<int, true> $idsInLocale
     * @param list<MenuItem>   $allItems
     */
    private function parentIdInLocale(MenuItem $item, string $locale, array $idsInLocale, array $allItems): int
    {
        $parent = $item->getParent();
        if (!$parent instanceof MenuItem) {
            return 0;
        }

        $parentId = $parent->getId();
        if ($parentId !== null && isset($idsInLocale[$parentId])) {
            return $parentId;
        }

        foreach ($this->translationSiblings($parent, $allItems) as $code => $sibling) {
            if ($code !== $locale) {
                continue;
            }
            $siblingId = $sibling->getId();
            if ($siblingId !== null && isset($idsInLocale[$siblingId])) {
                return $siblingId;
            }
        }

        return 0;
    }

    /**
     * @param array<int, list<MenuItem>> $childrenByParentId
     * @param array<int, Node>           $nodesById
     * @param list<MenuItem>             $allItems
     *
     * @return list<array{label: string, url: string, openInNewTab: bool, children: array}>
     */
    private function buildTree(
        array $childrenByParentId,
        array $nodesById,
        array $allItems,
        string $locale,
        ?int $parentId,
    ): array {
        $tree = [];

        foreach ($childrenByParentId[$parentId ?? 0] ?? [] as $item) {
            $url = $this->resolveUrl($item, $nodesById, $allItems, $locale);
            if ($url === null) {
                continue;
            }

            $tree[] = [
                'label' => $item->getLabel(),
                'url' => $url,
                'openInNewTab' => $item->isOpenInNewTab(),
                'children' => $this->buildTree($childrenByParentId, $nodesById, $allItems, $locale, $item->getId()),
            ];
        }

        return $tree;
    }

    /**
     * @param array<int, Node> $nodesById
     * @param list<MenuItem>   $allItems
     */
    private function resolveUrl(MenuItem $item, array $nodesById, array $allItems, string $locale): ?string
    {
        if ($item->getUrl() !== null) {
            return $item->getUrl();
        }

        if ($item->getNodeId() !== null) {
            $node = $nodesById[$item->getNodeId()] ?? null;
            if ($node !== null && $node->getDeletedAt() === null && $node->getStatus() === Node::STATUS_PUBLISHED) {
                return $this->nodePublicUrl($node);
            }
        }

        $default = $this->localeProvider->getDefaultCode();
        $fallback = null;
        foreach ($this->translationSiblings($item, $allItems) as $code => $sibling) {
            $url = $sibling->getUrl();
            if ($url === null) {
                continue;
            }
            if ($code === $locale) {
                return $url;
            }
            if ($code === $default) {
                $fallback = $url;
            } elseif ($fallback === null) {
                $fallback = $url;
            }
        }

        return $fallback;
    }

    private function nodePublicUrl(Node $node): string
    {
        $route = $this->contributions->nodeShowRoute($node->getType());

        if ($route !== null) {
            try {
                return $this->urlGenerator->generate($route, [
                    '_locale' => $node->getLocale(),
                    'slug' => $node->getSlug(),
                ]);
            } catch (RouteNotFoundException) {
                // Module inactive: fall through to the locale/slug path.
            }
        }

        return sprintf('/%s/%s', $node->getLocale(), $node->getSlug());
    }

    /**
     * @param list<MenuItem> $allItems
     *
     * @return array<string, MenuItem>
     */
    private function translationSiblings(MenuItem $item, array $allItems): array
    {
        $groupId = $item->getTranslationGroupId();
        $siblings = [$item->getLocale() => $item];

        if ($groupId === null) {
            return $siblings;
        }

        foreach ($allItems as $candidate) {
            if ((string) $candidate->getTranslationGroupId() === (string) $groupId) {
                $siblings[$candidate->getLocale()] = $candidate;
            }
        }

        return $siblings;
    }
}
