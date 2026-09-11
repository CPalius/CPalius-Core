<?php

declare(strict_types=1);

namespace Modules\Menu\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\OriginCachePurger;
use Modules\Menu\Twig\FrontMenuRuntime;
use Modules\Menu\Entity\Menu;
use Modules\Menu\Entity\MenuItem;
use Modules\Menu\Repository\MenuItemRepository;
use Modules\Menu\Repository\MenuRepository;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio CRUD for menus. Entities live in core (Asset pattern); menu.manage is site-wide, no own/any split.
 */
#[Route('/admin/menus', name: 'admin_menus_')]
#[IsGranted('menu.manage')]
final class MenuAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MenuRepository $menuRepository,
        private readonly MenuItemRepository $menuItemRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly CacheInterface $cacheApp,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
        private readonly OriginCachePurger $originCachePurger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.menu.menu_management', icon: 'heroicons:bars-3', panel: 'studio', priority: 40, capability: 'menu.manage', group: 'studio.group.appearance')]
    public function index(): Response
    {
        return $this->render('@MenuModule/admin/menus/index.html.twig', [
            'menus' => $this->menuRepository->findAll(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_menu_form');

            $name = trim((string) $request->request->get('name'));
            $identifier = trim((string) $request->request->get('identifier'));

            if ($name === '' || $identifier === '') {
                $this->addFlash('error', $this->translator->trans('menu.admin.error.name_identifier_required'));

                return $this->render('@MenuModule/admin/menus/create.html.twig', [
                    'formValues' => ['name' => $name, 'identifier' => $identifier],
                ]);
            }

            if ($this->menuRepository->findOneByIdentifier($identifier) !== null) {
                $this->addFlash('error', $this->translator->trans('menu.admin.error.identifier_taken', ['identifier' => $identifier]));

                return $this->render('@MenuModule/admin/menus/create.html.twig', [
                    'formValues' => ['name' => $name, 'identifier' => $identifier],
                ]);
            }

            $menu = new Menu($name, $identifier);
            $this->entityManager->persist($menu);
            $this->entityManager->flush();
        $this->originCachePurger->purgeAll();

            $this->addFlash('success', $this->translator->trans('menu.admin.flash.created', ['name' => $name]));

            return $this->redirectToRoute('admin_menus_edit', ['id' => $menu->getId()]);
        }

        return $this->render('@MenuModule/admin/menus/create.html.twig', [
            'formValues' => ['name' => '', 'identifier' => ''],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $menu = $this->findMenuOrFail($id);

        $items = $this->menuItemRepository->findAllByMenu($menu);
        $defaultLocale = $this->localeProvider->getDefaultCode();

        return $this->render('@MenuModule/admin/menus/edit.html.twig', [
            'menu' => $menu,
            'tree' => $this->buildGroupedTree($items, $defaultLocale),
            'parentChoices' => $this->parentChoiceRows($items, $defaultLocale),
            'locales' => $this->localeProvider->getLocales(),
            'defaultLocale' => $defaultLocale,
        ]);
    }

    #[Route('/{id}/items', name: 'add_item', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addItem(int $id, Request $request): Response
    {
        $menu = $this->findMenuOrFail($id);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $labels = $this->collectLocaleValues($request, 'labels');
        $urls = $this->collectLocaleUrls($request);

        if ($labels === []) {
            $this->addFlash('error', $this->translator->trans('menu.admin.error.label_required'));

            return $this->redirectToRoute('admin_menus_edit', ['id' => $id]);
        }

        $nodeId = $this->parseNullableInt($request->request->get('node_id'));
        $parentId = $this->parseNullableInt($request->request->get('parent_id'));
        $openInNewTab = $request->request->getBoolean('open_in_new_tab');

        $allItems = $this->menuItemRepository->findAllByMenu($menu);
        $parentCanonical = null;
        if ($parentId !== null) {
            $found = $this->menuItemRepository->find($parentId);
            if ($found instanceof MenuItem && $found->getMenu()->getId() === $menu->getId()) {
                $parentCanonical = $found;
            }
        }

        $anchor = null;
        $firstLabel = '';
        $writtenLocales = [];

        foreach ($this->localeProvider->getLocales() as $localeDef) {
            $code = $localeDef->code;
            if (!isset($labels[$code])) {
                continue;
            }

            $parent = $this->localeParent($parentCanonical, $code, $allItems);
            $siblingCount = \count($this->menuItemRepository->findByMenuParentAndLocale($menu, $parent?->getId(), $code));

            $item = new MenuItem($menu, $labels[$code], $code);
            $item->setParent($parent);
            $item->setUrl($urls[$code] ?? null);
            $item->setNodeId($nodeId);
            $item->setOpenInNewTab($openInNewTab);
            $item->setSortOrder($siblingCount);

            if ($anchor instanceof MenuItem) {
                $item->joinTranslationGroup($anchor->ensureTranslationGroup());
            } else {
                $item->ensureTranslationGroup();
                $anchor = $item;
                $firstLabel = $labels[$code];
            }

            $this->entityManager->persist($item);
            $writtenLocales[] = $code;
        }

        $this->entityManager->flush();
        $this->originCachePurger->purgeAll();

        foreach ($writtenLocales as $locale) {
            $this->invalidateMenuCache($menu, $locale);
        }

        $this->addFlash('success', $this->translator->trans('menu.admin.flash.item_added', ['label' => $firstLabel]));

        return $this->redirectToRoute('admin_menus_edit', ['id' => $id]);
    }

    /**
     * One-click copy of a menu item into another locale, linked in the same translation group.
     * Parent is remapped in the target locale when present; otherwise the copy becomes a root.
     */
    #[Route('/items/{itemId}/translate/{locale}', name: 'translate_item', methods: ['POST'], requirements: ['itemId' => '\d+', 'locale' => '%cpalius.locales_pattern%'])]
    public function translateItem(int $itemId, string $locale, Request $request): Response
    {
        $item = $this->findMenuItemOrFail($itemId);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $menu = $item->getMenu();
        $target = $this->localeProvider->resolve($locale);
        $redirect = $this->redirectToRoute('admin_menus_edit', ['id' => $menu->getId()]);

        if ($target === $item->getLocale()) {
            return $redirect;
        }

        // Load all items once; sibling/parent matching stays in memory (Law 6.1).
        $allItems = $this->menuItemRepository->findAllByMenu($menu);
        $siblings = $this->translationSiblings($item, $allItems);

        if (isset($siblings[$target])) {
            $this->addFlash('error', $this->translator->trans('menu.admin.error.translation_exists', [
                'label' => $item->getLabel(),
                'locale' => $target,
            ]));

            return $redirect;
        }

        $parent = $this->mapParentToLocale($item, $target, $allItems);

        $translation = new MenuItem($menu, $item->getLabel(), $target);
        $translation->setParent($parent);
        $translation->setUrl($item->getUrl());
        $translation->setNodeId($item->getNodeId());
        $translation->setOpenInNewTab($item->isOpenInNewTab());
        $translation->setSortOrder(
            \count($this->menuItemRepository->findByMenuParentAndLocale($menu, $parent?->getId(), $target)),
        );

        $translation->joinTranslationGroup($item->ensureTranslationGroup());

        $this->entityManager->persist($translation);
        $this->entityManager->flush();
        $this->originCachePurger->purgeAll();

        $this->invalidateMenuCache($menu, $target);

        $this->addFlash('success', $this->translator->trans('cp.translation_tabs.linked_flash', [
            'name' => $item->getLabel(),
            'locale' => $target,
        ]));

        return $redirect;
    }

    /**
     * Source parent's counterpart in the target locale, or null (root).
     *
     * @param list<MenuItem> $allItems Preloaded menu items (no extra query).
     */
    private function mapParentToLocale(MenuItem $item, string $target, array $allItems): ?MenuItem
    {
        $parent = $item->getParent();

        if (!$parent instanceof MenuItem) {
            return null;
        }

        return $this->translationSiblings($parent, $allItems)[$target] ?? null;
    }

    /**
     * Translation siblings from the already-loaded item list (Law 6.1, no extra query).
     *
     * @param list<MenuItem> $allItems
     *
     * @return array<string, MenuItem> locale => item (includes the source row)
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

    #[Route('/items/{itemId}/update', name: 'update_item', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function updateItem(int $itemId, Request $request): Response
    {
        $item = $this->findMenuItemOrFail($itemId);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $labels = $this->collectLocaleValues($request, 'labels');
        $urls = $this->collectLocaleUrls($request);

        if ($labels === []) {
            $this->addFlash('error', $this->translator->trans('menu.admin.error.label_required'));

            return $this->redirectToRoute('admin_menus_edit', ['id' => $item->getMenu()->getId()]);
        }

        $menu = $item->getMenu();
        $allItems = $this->menuItemRepository->findAllByMenu($menu);
        $siblings = $this->translationSiblings($item, $allItems);
        $groupId = $item->ensureTranslationGroup();
        $nodeId = $this->parseNullableInt($request->request->get('node_id'));
        $openInNewTab = $request->request->getBoolean('open_in_new_tab');
        $canonicalParent = $item->getParent();
        $writtenLocales = [];

        foreach ($this->localeProvider->getLocales() as $localeDef) {
            $code = $localeDef->code;
            $label = $labels[$code] ?? null;
            $existing = $siblings[$code] ?? null;

            if ($label === null) {
                continue;
            }

            if ($existing instanceof MenuItem) {
                $existing->setLabel($label);
                if (\array_key_exists($code, $urls)) {
                    $existing->setUrl($urls[$code]);
                }
                $existing->setNodeId($nodeId);
                $existing->setOpenInNewTab($openInNewTab);
                $writtenLocales[] = $code;
                continue;
            }

            $parent = $this->localeParent($canonicalParent, $code, $allItems);
            $translation = new MenuItem($menu, $label, $code);
            $translation->setParent($parent);
            $translation->setUrl($urls[$code] ?? $item->getUrl());
            $translation->setNodeId($nodeId);
            $translation->setOpenInNewTab($openInNewTab);
            $translation->setSortOrder($item->getSortOrder());
            $translation->joinTranslationGroup($groupId);
            $this->entityManager->persist($translation);
            $writtenLocales[] = $code;
        }

        $this->entityManager->flush();
        $this->originCachePurger->purgeAll();

        foreach (array_unique($writtenLocales) as $locale) {
            $this->invalidateMenuCache($menu, $locale);
        }

        $this->addFlash('success', $this->translator->trans('menu.admin.flash.item_updated'));

        return $this->redirectToRoute('admin_menus_edit', ['id' => $menu->getId()]);
    }

    #[Route('/items/{itemId}/delete', name: 'delete_item', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function deleteItem(int $itemId, Request $request): Response
    {
        $item = $this->findMenuItemOrFail($itemId);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $menu = $item->getMenu();
        $allItems = $this->menuItemRepository->findAllByMenu($menu);
        $locales = [];

        foreach ($this->translationSiblings($item, $allItems) as $sibling) {
            $locales[] = $sibling->getLocale();
            $this->entityManager->remove($sibling);
        }

        $this->entityManager->flush();
        $this->originCachePurger->purgeAll();

        foreach (array_unique($locales) as $locale) {
            $this->invalidateMenuCache($menu, $locale);
        }

        $this->addFlash('success', $this->translator->trans('menu.admin.flash.item_deleted'));

        return $this->redirectToRoute('admin_menus_edit', ['id' => $menu->getId()]);
    }

    /**
     * Apply SortableJS drag-and-drop order/hierarchy. JSON body: [{itemId, parentId, sortOrder}, ...]
     */
    #[Route('/{id}/reorder', name: 'reorder', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reorder(int $id, Request $request): JsonResponse
    {
        $menu = $this->findMenuOrFail($id);

        $submittedToken = (string) $request->headers->get('X-CSRF-Token');
        if (!$this->isCsrfTokenValid('admin_menu_reorder', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        /** @var list<array{itemId: int, parentId: ?int, sortOrder: int}> $payload */
        $payload = json_decode((string) $request->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $affectedLocales = [];
        $allItems = $this->menuItemRepository->findAllByMenu($menu);

        foreach ($payload as $row) {
            $item = $this->menuItemRepository->find($row['itemId']);
            if (!$item instanceof MenuItem || $item->getMenu()->getId() !== $menu->getId()) {
                continue;
            }

            $parentCanonical = $row['parentId'] !== null ? $this->menuItemRepository->find($row['parentId']) : null;
            if ($parentCanonical instanceof MenuItem && $parentCanonical->getMenu()->getId() !== $menu->getId()) {
                $parentCanonical = null;
            }

            foreach ($this->translationSiblings($item, $allItems) as $sibling) {
                $sibling->setParent($this->localeParent($parentCanonical, $sibling->getLocale(), $allItems));
                $sibling->setSortOrder((int) $row['sortOrder']);
                $affectedLocales[$sibling->getLocale()] = true;
            }
        }

        $this->entityManager->flush();
        $this->originCachePurger->purgeAll();

        foreach (array_keys($affectedLocales) as $locale) {
            $this->invalidateMenuCache($menu, $locale);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * One row per translation group for the Studio tree (all locale labels on that row).
     *
     * @param list<MenuItem> $items
     *
     * @return list<array{canonical: MenuItem, byLocale: array<string, MenuItem>, children: array}>
     */
    private function buildGroupedTree(array $items, string $defaultCode): array
    {
        $groups = $this->groupByTranslation($items);
        $itemIdToGroupKey = [];

        foreach ($groups as $key => $byLocale) {
            foreach ($byLocale as $member) {
                $id = $member->getId();
                if ($id !== null) {
                    $itemIdToGroupKey[$id] = $key;
                }
            }
        }

        $childrenKeys = [];
        foreach ($groups as $key => $byLocale) {
            $canonical = $this->pickCanonical($byLocale, $defaultCode);
            $parent = $canonical->getParent();
            $parentKey = 'root';
            if ($parent instanceof MenuItem && $parent->getId() !== null) {
                $parentKey = $itemIdToGroupKey[$parent->getId()] ?? 'root';
                if ($parentKey === $key) {
                    $parentKey = 'root';
                }
            }
            $childrenKeys[$parentKey][] = $key;
        }

        $build = function (string $parentKey) use (&$build, $groups, $childrenKeys, $defaultCode): array {
            $nodes = [];
            foreach ($childrenKeys[$parentKey] ?? [] as $key) {
                $byLocale = $groups[$key];
                $canonical = $this->pickCanonical($byLocale, $defaultCode);
                $nodes[] = [
                    'canonical' => $canonical,
                    'byLocale' => $byLocale,
                    'sortOrder' => $canonical->getSortOrder(),
                    'children' => $build($key),
                ];
            }
            usort($nodes, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);
            foreach ($nodes as &$node) {
                unset($node['sortOrder']);
            }
            unset($node);

            return $nodes;
        };

        return $build('root');
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return array<string, array<string, MenuItem>>
     */
    private function groupByTranslation(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            $groupId = $item->getTranslationGroupId();
            $key = $groupId !== null ? 'g:'.$groupId : 'i:'.$id;
            $groups[$key][$item->getLocale()] = $item;
        }

        return $groups;
    }

    /**
     * @param array<string, MenuItem> $byLocale
     */
    private function pickCanonical(array $byLocale, string $defaultCode): MenuItem
    {
        return $byLocale[$defaultCode] ?? array_values($byLocale)[0];
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<array{id: int, label: string}>
     */
    private function parentChoiceRows(array $items, string $defaultCode): array
    {
        $rows = [];
        foreach ($this->groupByTranslation($items) as $byLocale) {
            $canonical = $this->pickCanonical($byLocale, $defaultCode);
            $id = $canonical->getId();
            if ($id === null) {
                continue;
            }
            $parts = [];
            foreach ($byLocale as $code => $member) {
                $parts[] = $member->getLabel().' ('.$code.')';
            }
            $rows[] = ['id' => $id, 'label' => implode(' · ', $parts)];
        }

        return $rows;
    }

    /**
     * @param list<MenuItem> $allItems
     */
    private function localeParent(?MenuItem $canonicalParent, string $locale, array $allItems): ?MenuItem
    {
        if (!$canonicalParent instanceof MenuItem) {
            return null;
        }
        if ($canonicalParent->getLocale() === $locale) {
            return $canonicalParent;
        }

        return $this->translationSiblings($canonicalParent, $allItems)[$locale] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function collectLocaleValues(Request $request, string $field): array
    {
        $raw = $request->request->all($field);
        if (!\is_array($raw)) {
            return [];
        }

        $supported = [];
        foreach ($this->localeProvider->getLocales() as $locale) {
            $supported[$locale->code] = true;
        }

        $out = [];
        foreach ($raw as $code => $value) {
            if (!\is_string($code) || !isset($supported[$code])) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            $out[$code] = $trimmed;
        }

        return $out;
    }

    /**
     * @return array<string, string|null>
     */
    private function collectLocaleUrls(Request $request): array
    {
        $raw = $request->request->all('urls');
        if (!\is_array($raw)) {
            return [];
        }

        $supported = [];
        foreach ($this->localeProvider->getLocales() as $locale) {
            $supported[$locale->code] = true;
        }

        $out = [];
        foreach ($raw as $code => $value) {
            if (!\is_string($code) || !isset($supported[$code])) {
                continue;
            }
            $trimmed = trim((string) $value);
            $out[$code] = $trimmed !== '' ? $trimmed : null;
        }

        return $out;
    }

    /**
     * Empty string means "no node selected"; skip Request::getInt() to avoid FILTER_VALIDATE_INT errors.
     */
    private function parseNullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private function invalidateMenuCache(Menu $menu, string $locale): void
    {
        $this->cacheApp->delete(FrontMenuRuntime::cacheKey($menu->getIdentifier(), $locale));
    }

    private function findMenuOrFail(int $id): Menu
    {
        $menu = $this->menuRepository->find($id);
        if (!$menu instanceof Menu) {
            throw new NotFoundHttpException($this->translator->trans('menu.admin.error.not_found'));
        }

        return $menu;
    }

    private function findMenuItemOrFail(int $itemId): MenuItem
    {
        $item = $this->menuItemRepository->find($itemId);
        if (!$item instanceof MenuItem) {
            throw new NotFoundHttpException($this->translator->trans('menu.admin.error.item_not_found'));
        }

        return $item;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $submitted)) {
            throw $this->createAccessDeniedException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}
