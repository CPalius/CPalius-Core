<?php

declare(strict_types=1);

namespace Modules\Widget\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\Theme\ThemeRegistry;
use Modules\Widget\Entity\WidgetInstance;
use Modules\Widget\Repository\WidgetInstanceRepository;
use Modules\Widget\Service\WidgetFieldSanitizer;
use Modules\Widget\Service\WidgetManager;
use Modules\Widget\Service\WidgetRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio → Widgets: pick an area, add widgets from the catalogue, set each one up, drag them into order,
 * switch them off, or start from a ready-made arrangement. The public site reads the same rows through
 * WidgetAreaRenderer, so what is saved here is what visitors see.
 */
#[Route('/admin/widgets', name: 'admin_widgets_')]
#[IsGranted('widget.manage')]
final class WidgetAdminController extends AbstractController
{
    private const AUDIENCES = [WidgetInstance::AUDIENCE_ALL, WidgetInstance::AUDIENCE_GUESTS, WidgetInstance::AUDIENCE_MEMBERS];

    public function __construct(
        private readonly WidgetInstanceRepository $widgets,
        private readonly WidgetRegistry $registry,
        private readonly WidgetManager $manager,
        private readonly WidgetFieldSanitizer $sanitizer,
        private readonly LocaleProvider $locales,
        private readonly ThemeRegistry $themes,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'widget.studio.menu', icon: 'heroicons:rectangle-group', panel: 'studio', priority: 35, capability: 'widget.manage', group: 'studio.group.content')]
    public function index(Request $request): Response
    {
        // A site that updated from the old fixed blog sidebar gets its widgets rebuilt from the old
        // settings the first time anyone opens this screen (the update hook does the same earlier).
        $this->manager->seedMissing();

        $areas = $this->registry->areas();
        $areaId = $request->query->getString('area');
        $area = $areas[$areaId] ?? ($areas !== [] ? reset($areas) : null);

        $rows = [];
        if ($area !== null) {
            foreach ($this->widgets->findForArea($area->id) as $widget) {
                $type = $this->registry->type($widget->getType());
                $rows[] = [
                    'widget' => $widget,
                    'name' => $this->manager->titleFor($widget, $request->getLocale()),
                    'type' => $type,
                    // The type's module is switched off: the row is kept but cannot be edited or drawn.
                    'orphan' => $type === null,
                ];
            }
        }

        return $this->render('@WidgetModule/admin/index.html.twig', [
            'areas' => $areas,
            'area' => $area,
            'rows' => $rows,
            'catalogue' => $area !== null ? $this->registry->typesByGroupFor($area->id) : [],
            'areaGroups' => $this->registry->areasByModule(),
            'borrowed' => $area !== null ? $this->widgets->findShownIn($area->id) : [],
            'areaLabels' => array_map(static fn ($a): string => $a->label, $areas),
            'themeNames' => $this->themeNames(),
            'localeNames' => $this->localeNames(),
        ]);
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->assertCsrf($request);
        $area = $request->request->getString('area');
        $widget = $this->manager->create($area, $request->request->getString('type'));
        if ($widget === null) {
            throw new NotFoundHttpException();
        }
        $this->manager->save();

        // Straight to its settings: adding is "pick it, then set it up".
        return $this->redirectToRoute('admin_widgets_edit', ['id' => $widget->getId()]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $widget = $this->find($id);
        $type = $this->registry->type($widget->getType());
        if ($type === null) {
            $this->addFlash('error', $this->translator->trans('widget.studio.error.type_missing'));

            return $this->redirectToRoute('admin_widgets_index', ['area' => $widget->getArea()]);
        }

        $fields = $type->fields();

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            // Settings first: setSettings() replaces the array the per-language titles live in.
            $widget->setSettings($this->sanitizer->sanitize($fields, $request->request->all('settings'), $this->locales->getCodes()));
            $this->manager->setPostedTitles($widget, $request->request->all('title'));
            $widget->setLocale($this->validLocale($request->request->getString('locale')));
            $widget->setVisibility(
                array_values(array_intersect(WidgetRegistry::PAGES, array_map('strval', $request->request->all('pages')))),
                \in_array($request->request->getString('audience'), self::AUDIENCES, true) ? $request->request->getString('audience') : WidgetInstance::AUDIENCE_ALL,
                $this->cssClass($request->request->getString('css')),
            );
            $widget->setPlacement(
                array_values(array_intersect(array_keys($this->registry->areas()), array_map('strval', $request->request->all('areas')))),
                array_values(array_intersect(array_keys($this->themeNames()), array_map('strval', $request->request->all('themes')))),
                $this->stamp($request->request->getString('show_from')),
                $this->stamp($request->request->getString('show_until')),
            );
            $widget->setActive($request->request->has('active'));
            $this->manager->save();

            $this->addFlash('success', $this->translator->trans('widget.studio.saved'));

            return $this->redirectToRoute('admin_widgets_index', ['area' => $widget->getArea()]);
        }

        return $this->render('@WidgetModule/admin/edit.html.twig', [
            'widget' => $widget,
            'titles' => $this->manager->titlesForForm($widget),
            'type' => $type,
            'fields' => $fields,
            'values' => $this->sanitizer->forForm($fields, $this->sanitizer->withDefaults($fields, $widget->getSettings()), $this->locales->getCodes(), $this->locales->getDefaultCode()),
            'pages' => WidgetRegistry::PAGES,
            'locales' => $this->localeNames(),
            'area' => $this->registry->area($widget->getArea()),
            'areaGroups' => $this->registry->areasByModule(),
            'themeNames' => $this->themeNames(),
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $this->assertCsrf($request);
        $widget = $this->find($id);
        $widget->setActive(!$widget->isActive());
        $this->manager->save();

        return $this->redirectToRoute('admin_widgets_index', ['area' => $widget->getArea()]);
    }

    #[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request): Response
    {
        $this->assertCsrf($request);
        $source = $this->find($id);
        $this->manager->duplicate($source);
        $this->manager->save();
        $this->addFlash('success', $this->translator->trans('widget.studio.duplicated'));

        return $this->redirectToRoute('admin_widgets_index', ['area' => $source->getArea()]);
    }

    #[Route('/{id}/copy', name: 'copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copy(int $id, Request $request): Response
    {
        $this->assertCsrf($request);
        $source = $this->find($id);
        $copy = $this->manager->copyToArea($source, $request->request->getString('to'));

        if ($copy === null) {
            $this->addFlash('error', $this->translator->trans('widget.studio.error.copy_refused'));

            return $this->redirectToRoute('admin_widgets_index', ['area' => $source->getArea()]);
        }

        $this->manager->save();
        $this->addFlash('success', $this->translator->trans('widget.studio.copied'));

        // Straight to the copy: the point of copying is to change it for the other module.
        return $this->redirectToRoute('admin_widgets_edit', ['id' => $copy->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $this->assertCsrf($request);
        $widget = $this->find($id);
        $area = $widget->getArea();
        $this->manager->delete($widget);
        $this->addFlash('success', $this->translator->trans('widget.studio.deleted'));

        return $this->redirectToRoute('admin_widgets_index', ['area' => $area]);
    }

    /**
     * Drag-and-drop order: the page posts the area and the ids in their new order.
     */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_widgets', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $ids = array_map('intval', $request->request->all('ids'));
        $this->manager->reorder($request->request->getString('area'), array_values(array_filter($ids, static fn (int $id): bool => $id > 0)));
        $this->manager->save();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/preset', name: 'preset', methods: ['POST'])]
    public function preset(Request $request): Response
    {
        $this->assertCsrf($request);
        $area = $request->request->getString('area');

        if ($this->manager->applyPreset($area, $request->request->getString('preset'))) {
            $this->addFlash('success', $this->translator->trans('widget.studio.preset_applied'));
        } else {
            $this->addFlash('error', $this->translator->trans('widget.studio.error.preset_unknown'));
        }

        return $this->redirectToRoute('admin_widgets_index', ['area' => $area]);
    }

    private function find(int $id): WidgetInstance
    {
        $widget = $this->widgets->find($id);
        if (!$widget instanceof WidgetInstance) {
            throw new NotFoundHttpException();
        }

        return $widget;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_widgets', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }

    /**
     * @return array<string, string> code => native name, for the language pickers
     */
    private function localeNames(): array
    {
        $names = [];
        foreach ($this->locales->getLocales() as $locale) {
            $names[$locale->code] = $locale->nativeName ?: $locale->name;
        }

        return $names;
    }

    /** @return array<string, string> theme dir name => display name */
    private function themeNames(): array
    {
        $names = [];
        foreach ($this->themes->all() as $dir => $theme) {
            $names[$dir] = $theme->name;
        }

        return $names;
    }

    /** datetime-local value or '' — anything else is dropped rather than stored. */
    private function stamp(string $raw): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw) === 1 ? $raw : '';
    }

    private function validLocale(string $code): ?string
    {
        return $code !== '' && \in_array($code, $this->locales->getCodes(), true) ? $code : null;
    }

    /** Class names only: letters, digits, dash, underscore and single spaces. */
    private function cssClass(string $raw): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9_\- ]/', '', $raw))), 0, 100);
    }
}
