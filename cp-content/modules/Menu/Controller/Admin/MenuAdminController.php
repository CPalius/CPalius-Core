<?php

namespace Modules\Menu\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Menu\Twig\FrontMenuRuntime;
use App\Entity\Menu;
use App\Entity\MenuItem;
use App\Repository\MenuItemRepository;
use App\Repository\MenuRepository;
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
 * Menu/MenuItem entity'leri (Asset deseniyle simetrik) çekirdekte yaşar —
 * bu controller sadece yönetim arayüzünü sağlar. menu.manage tek bir
 * capability yeterlidir (own/any ayrımı yok, menüler site-geneli
 * yapılandırmadır, kullanıcıya özel değildir).
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
        private readonly TranslatorInterface $translator,
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
        $tree = $this->buildAdminTree($items, null);

        return $this->render('@MenuModule/admin/menus/edit.html.twig', [
            'menu' => $menu,
            'tree' => $tree,
            'allItems' => $items,
        ]);
    }

    #[Route('/{id}/items', name: 'add_item', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addItem(int $id, Request $request): Response
    {
        $menu = $this->findMenuOrFail($id);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $label = trim((string) $request->request->get('label'));
        $locale = trim((string) $request->request->get('locale')) ?: 'tr';
        $url = trim((string) $request->request->get('url'));
        $nodeId = $this->parseNullableInt($request->request->get('node_id'));
        $parentId = $this->parseNullableInt($request->request->get('parent_id'));
        $openInNewTab = $request->request->getBoolean('open_in_new_tab');

        if ($label === '') {
            $this->addFlash('error', $this->translator->trans('menu.admin.error.label_required'));

            return $this->redirectToRoute('admin_menus_edit', ['id' => $id]);
        }

        $parent = $parentId !== null ? $this->menuItemRepository->find($parentId) : null;

        $siblingCount = \count($this->menuItemRepository->findByMenuParentAndLocale($menu, $parent?->getId(), $locale));

        $item = new MenuItem($menu, $label, $locale);
        $item->setParent($parent);
        $item->setUrl($url !== '' ? $url : null);
        $item->setNodeId($nodeId);
        $item->setOpenInNewTab($openInNewTab);
        $item->setSortOrder($siblingCount);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->invalidateMenuCache($menu, $locale);

        $this->addFlash('success', $this->translator->trans('menu.admin.flash.item_added', ['label' => $label]));

        return $this->redirectToRoute('admin_menus_edit', ['id' => $id]);
    }

    #[Route('/items/{itemId}/update', name: 'update_item', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function updateItem(int $itemId, Request $request): Response
    {
        $item = $this->findMenuItemOrFail($itemId);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $label = trim((string) $request->request->get('label'));
        if ($label === '') {
            $this->addFlash('error', $this->translator->trans('menu.admin.error.label_required'));

            return $this->redirectToRoute('admin_menus_edit', ['id' => $item->getMenu()->getId()]);
        }

        $item->setLabel($label);
        $url = trim((string) $request->request->get('url'));
        $item->setUrl($url !== '' ? $url : null);
        $item->setNodeId($this->parseNullableInt($request->request->get('node_id')));
        $item->setOpenInNewTab($request->request->getBoolean('open_in_new_tab'));

        $this->entityManager->flush();

        $this->invalidateMenuCache($item->getMenu(), $item->getLocale());

        $this->addFlash('success', $this->translator->trans('menu.admin.flash.item_updated'));

        return $this->redirectToRoute('admin_menus_edit', ['id' => $item->getMenu()->getId()]);
    }

    #[Route('/items/{itemId}/delete', name: 'delete_item', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function deleteItem(int $itemId, Request $request): Response
    {
        $item = $this->findMenuItemOrFail($itemId);
        $this->assertValidCsrf($request, 'admin_menu_form');

        $menu = $item->getMenu();
        $locale = $item->getLocale();

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        $this->invalidateMenuCache($menu, $locale);

        $this->addFlash('success', $this->translator->trans('menu.admin.flash.item_deleted'));

        return $this->redirectToRoute('admin_menus_edit', ['id' => $menu->getId()]);
    }

    /**
     * Basit sürükle-bırak (SortableJS) sonrası sıralama/hiyerarşi güncellemesi.
     * JSON body: [{itemId, parentId, sortOrder}, ...]
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

        foreach ($payload as $row) {
            $item = $this->menuItemRepository->find($row['itemId']);
            if (!$item instanceof MenuItem || $item->getMenu()->getId() !== $menu->getId()) {
                continue;
            }

            $parent = $row['parentId'] !== null ? $this->menuItemRepository->find($row['parentId']) : null;
            $item->setParent($parent);
            $item->setSortOrder((int) $row['sortOrder']);

            $affectedLocales[$item->getLocale()] = true;
        }

        $this->entityManager->flush();

        foreach (array_keys($affectedLocales) as $locale) {
            $this->invalidateMenuCache($menu, $locale);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<array{item: MenuItem, children: array}>
     */
    private function buildAdminTree(array $items, ?int $parentId): array
    {
        $tree = [];

        foreach ($items as $item) {
            $currentParentId = $item->getParent()?->getId();
            if ($currentParentId !== $parentId) {
                continue;
            }

            $tree[] = [
                'item' => $item,
                'children' => $this->buildAdminTree($items, $item->getId()),
            ];
        }

        return $tree;
    }

    /**
     * Request::getInt() boş string geldiğinde ("" node seçilmemiş demektir)
     * FILTER_VALIDATE_INT başarısız olur ve exception fırlatır — bu yüzden
     * doğrudan getInt() yerine burada elle boş kontrolü yapılır.
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
