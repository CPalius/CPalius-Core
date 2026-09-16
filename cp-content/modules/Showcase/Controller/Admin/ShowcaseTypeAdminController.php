<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Field\Repository\FieldDefinitionRepository;
use App\Core\Localization\LocaleProvider;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Modules\Showcase\Service\ShowcasePresetLibrary;
use Modules\Showcase\Service\ShowcaseTypeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Showcase types: the screen where a site decides what its showcase is FOR.
 *
 * Plain POST + CSRF + redirect, matching the other structure screens in this
 * codebase (categories, fields, webhooks) rather than introducing a Form type
 * for a handful of inputs.
 */
#[Route('/admin/showcase/types', name: 'admin_showcase_types_')]
#[IsGranted('showcase.type.manage')]
final class ShowcaseTypeAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_showcase_type';

    public function __construct(
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeManager $typeManager,
        private readonly ShowcasePresetLibrary $presets,
        private readonly FieldDefinitionRepository $fieldDefinitions,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'showcase.menu.types', icon: 'heroicons:squares-2x2', panel: 'studio', priority: 41, capability: 'showcase.type.manage', parent: 'admin_showcase_dashboard')]
    public function index(): Response
    {
        $rows = [];

        foreach ($this->types->findAllOrdered() as $type) {
            $rows[] = [
                'type' => $type,
                'itemCount' => $this->items->countForType($type),
                'fieldCount' => \count($this->fieldDefinitions->findByBundle($type->fieldBundle())),
            ];
        }

        return $this->render('@ShowcaseModule/admin/types/index.html.twig', [
            'rows' => $rows,
            'presets' => $this->presets->catalogue(),
            'locales' => $this->localeProvider->getLocales(),
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            $result = $this->typeManager->create(
                strtolower(trim((string) $request->request->get('machine_name'))),
                $this->labelsFromRequest($request),
                $this->featuresFromRequest($request),
                (string) $request->request->get('icon', 'heroicons:cube'),
                $request->request->getBoolean('with_vocabulary'),
            );

            if ($result['type'] === null) {
                $this->addFlash('error', $this->translator->trans((string) $result['error']));

                return $this->renderForm(null, $request);
            }

            $this->addFlash('success', $this->translator->trans('showcase.types.flash.created', [
                'name' => $result['type']->label($this->localeProvider->getDefaultCode()),
            ]));

            return $this->redirectToRoute('admin_showcase_fields_index', ['machineName' => $result['type']->getMachineName()]);
        }

        return $this->renderForm(null, $request);
    }

    /**
     * Creates a type together with a ready-made field schema. This is the answer
     * to "I want to list cars" without a tour of the field system first — the
     * seeded fields are ordinary FieldDefinitions the operator edits afterwards.
     */
    #[Route('/preset/{presetId}', name: 'preset', methods: ['POST'], requirements: ['presetId' => '[a-z][a-z0-9_]{0,31}'])]
    public function preset(string $presetId, Request $request): Response
    {
        $this->assertCsrf($request);

        if (!$this->presets->has($presetId)) {
            throw new NotFoundHttpException();
        }

        $result = $this->presets->apply($presetId);

        if ($result['type'] === null) {
            $this->addFlash('error', $this->translator->trans((string) $result['error']));

            return $this->redirectToRoute('admin_showcase_types_index');
        }

        $this->addFlash('success', $this->translator->trans('showcase.types.flash.preset_applied', [
            'name' => $result['type']->label($this->localeProvider->getDefaultCode()),
            'count' => $result['fieldsCreated'],
        ]));

        return $this->redirectToRoute('admin_showcase_fields_index', ['machineName' => $result['type']->getMachineName()]);
    }

    #[Route('/{machineName}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['machineName' => '[a-z][a-z0-9_]{0,31}'])]
    public function edit(string $machineName, Request $request): Response
    {
        $type = $this->findTypeOrFail($machineName);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            $result = $this->typeManager->update(
                $type,
                $this->labelsFromRequest($request),
                $this->featuresFromRequest($request),
                (string) $request->request->get('icon', 'heroicons:cube'),
                $request->request->getBoolean('enabled'),
                (int) $request->request->get('weight', 0),
                $request->request->getBoolean('with_vocabulary'),
            );

            if (!$result['success']) {
                $this->addFlash('error', $this->translator->trans((string) $result['error']));

                return $this->renderForm($type, $request);
            }

            $this->addFlash('success', $this->translator->trans('showcase.types.flash.updated', [
                'name' => $type->label($this->localeProvider->getDefaultCode()),
            ]));

            return $this->redirectToRoute('admin_showcase_types_index');
        }

        return $this->renderForm($type, $request);
    }

    #[Route('/{machineName}/delete', name: 'delete', methods: ['POST'], requirements: ['machineName' => '[a-z][a-z0-9_]{0,31}'])]
    public function delete(string $machineName, Request $request): Response
    {
        $type = $this->findTypeOrFail($machineName);
        $this->assertCsrf($request);

        $name = $type->label($this->localeProvider->getDefaultCode());
        $result = $this->typeManager->delete($type);

        if (!$result['success']) {
            $this->addFlash('error', $this->translator->trans((string) $result['error']));

            return $this->redirectToRoute('admin_showcase_types_index');
        }

        $this->addFlash('success', $this->translator->trans('showcase.types.flash.deleted', ['name' => $name]));

        return $this->redirectToRoute('admin_showcase_types_index');
    }

    private function renderForm(?ShowcaseType $type, Request $request): Response
    {
        $submitted = $request->isMethod('POST');

        return $this->render('@ShowcaseModule/admin/types/form.html.twig', [
            'type' => $type,
            'locales' => $this->localeProvider->getLocales(),
            'featureKeys' => array_keys(ShowcaseType::FEATURES),
            'features' => $submitted ? $this->featuresFromRequest($request) : ($type?->features() ?? ShowcaseType::FEATURES),
            'labels' => $submitted ? $this->labelsFromRequest($request) : $this->labelsFromType($type),
            'machineName' => $submitted ? (string) $request->request->get('machine_name', '') : ($type?->getMachineName() ?? ''),
            'icon' => $submitted ? (string) $request->request->get('icon', '') : ($type?->getIcon() ?? 'heroicons:cube'),
            'enabled' => $submitted ? $request->request->getBoolean('enabled') : ($type?->isEnabled() ?? true),
            'weight' => $submitted ? (int) $request->request->get('weight', 0) : ($type?->getWeight() ?? 0),
            'withVocabulary' => $submitted
                ? $request->request->getBoolean('with_vocabulary')
                : ($type === null || $type->getVocabulary() !== null),
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    private function labelsFromRequest(Request $request): array
    {
        $raw = $request->request->all('labels');
        $out = [];

        foreach ($this->localeProvider->getCodes() as $code) {
            $row = \is_array($raw[$code] ?? null) ? $raw[$code] : [];
            $out[$code] = [
                'label' => (string) ($row['label'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    private function labelsFromType(?ShowcaseType $type): array
    {
        $out = [];

        foreach ($this->localeProvider->getCodes() as $code) {
            $translation = $type?->getTranslation($code);
            $out[$code] = [
                'label' => $translation?->getLabel() ?? '',
                'description' => $translation?->getDescription() ?? '',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    private function featuresFromRequest(Request $request): array
    {
        $raw = $request->request->all('features');
        $out = [];

        foreach (array_keys(ShowcaseType::FEATURES) as $feature) {
            $out[$feature] = !empty($raw[$feature]);
        }

        return $out;
    }

    private function findTypeOrFail(string $machineName): ShowcaseType
    {
        $type = $this->types->findOneByMachineName($machineName);

        if (!$type instanceof ShowcaseType) {
            throw new NotFoundHttpException($this->translator->trans('showcase.types.error.not_found'));
        }

        return $type;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }
    }
}
