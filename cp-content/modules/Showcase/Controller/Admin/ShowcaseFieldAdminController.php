<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller\Admin;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Repository\ShowcaseItemIndexRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-type field designer.
 *
 * The core /aacp/fields screen can already edit any bundle, including these, but
 * it is a developer-level tool behind system.fields.manage. This controller does
 * the same job scoped to ONE showcase type and gated by showcase.field.manage, so
 * the person running a marketplace can shape their listings without being handed
 * the keys to every bundle in the installation.
 *
 * Field name and type stay immutable after creation, exactly as in core: the name
 * keys the stored JSON value and the flat index, and retyping a field would
 * reinterpret data already saved under it.
 */
#[Route('/admin/showcase/types/{machineName}/fields', name: 'admin_showcase_fields_', requirements: ['machineName' => '[a-z][a-z0-9_]{0,31}'])]
#[IsGranted('showcase.field.manage')]
final class ShowcaseFieldAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_showcase_field';

    public function __construct(
        private readonly ShowcaseTypeRepository $types,
        private readonly FieldDefinitionRepository $definitions,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly FieldDefinitionRegistry $registry,
        private readonly ShowcaseItemIndexRepository $itemIndex,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $machineName): Response
    {
        $type = $this->findTypeOrFail($machineName);

        return $this->render('@ShowcaseModule/admin/fields/index.html.twig', [
            'type' => $type,
            'bundle' => $type->fieldBundle(),
            'fields' => $this->definitions->findByBundle($type->fieldBundle()),
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(string $machineName, Request $request): Response
    {
        return $this->renderForm($this->findTypeOrFail($machineName), null, $request);
    }

    #[Route('/{name}/edit', name: 'edit', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}'])]
    public function edit(string $machineName, string $name, Request $request): Response
    {
        $type = $this->findTypeOrFail($machineName);

        return $this->renderForm($type, $this->findFieldOrFail($type, $name), $request);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(string $machineName, Request $request): Response
    {
        $type = $this->findTypeOrFail($machineName);
        $this->assertCsrf($request);

        $bundle = $type->fieldBundle();
        $name = trim((string) $request->request->get('name'));
        $fieldType = trim((string) $request->request->get('type'));

        $definition = $this->definitions->findOneByBundleAndName($bundle, $name);

        if ($definition === null) {
            if (preg_match(FieldDefinition::NAME_PATTERN, $name) !== 1) {
                return $this->backToForm($type, null, 'showcase.fields.error.bad_name');
            }

            if (!$this->fieldTypes->has($fieldType)) {
                return $this->backToForm($type, null, 'showcase.fields.error.bad_type');
            }

            $definition = new FieldDefinition($bundle, $name, $fieldType, $name);
            $this->entityManager->persist($definition);
        } else {
            // Immutable once data may exist under this name.
            $fieldType = $definition->getType();
        }

        $label = trim((string) $request->request->get('label'));

        $definition
            ->setLabel($label !== '' ? mb_substr($label, 0, 191) : ucfirst(str_replace('_', ' ', $name)))
            ->setHelp((string) $request->request->get('help'))
            ->setRequired($request->request->get('required') !== null)
            ->setTranslatable($request->request->get('translatable') !== null)
            ->setQueryable($request->request->get('queryable') !== null)
            ->setFieldGroup((string) $request->request->get('field_group'))
            ->setWeight((int) $request->request->get('weight', 0))
            ->setCardinality($this->parseCardinality((string) $request->request->get('cardinality', '1')));

        /** @var array<string, mixed> $rawSettings */
        $rawSettings = (array) $request->request->all('settings');
        $definition->setSettings($this->fieldTypes->get($fieldType)->normalizeSettings($rawSettings));

        $this->entityManager->flush();
        $this->registry->invalidate($bundle);

        // Turning "queryable" off leaves rows behind that would keep answering
        // filters for a field no longer offered, so this type's index is cleaned.
        if (!$definition->isQueryable()) {
            $this->itemIndex->deleteForType($type, [$definition->getName()]);
        }

        $this->addFlash('success', $this->translator->trans('showcase.fields.flash.saved', ['name' => $definition->getLabel()]));

        return $this->redirectToRoute('admin_showcase_fields_index', ['machineName' => $machineName]);
    }

    #[Route('/{name}/delete', name: 'delete', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}'])]
    public function delete(string $machineName, string $name, Request $request): Response
    {
        $type = $this->findTypeOrFail($machineName);
        $this->assertCsrf($request);

        $definition = $this->findFieldOrFail($type, $name);

        $this->entityManager->remove($definition);
        $this->entityManager->flush();
        $this->registry->invalidate($type->fieldBundle());
        $this->itemIndex->deleteForType($type, [$name]);

        // The stored VALUES stay in each item's data JSON on purpose: recreating a
        // field with the same name brings the old content back, which is what an
        // operator expects after deleting a field by mistake.
        $this->addFlash('success', $this->translator->trans('showcase.fields.flash.deleted', ['name' => $name]));

        return $this->redirectToRoute('admin_showcase_fields_index', ['machineName' => $machineName]);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(string $machineName, Request $request): Response
    {
        $type = $this->findTypeOrFail($machineName);
        $this->assertCsrf($request);

        /** @var array<string, mixed> $order */
        $order = (array) $request->request->all('weight');

        foreach ($this->definitions->findByBundle($type->fieldBundle()) as $definition) {
            if (\array_key_exists($definition->getName(), $order)) {
                $definition->setWeight((int) $order[$definition->getName()]);
            }
        }

        $this->entityManager->flush();
        $this->registry->invalidate($type->fieldBundle());

        return $this->redirectToRoute('admin_showcase_fields_index', ['machineName' => $machineName]);
    }

    private function renderForm(ShowcaseType $type, ?FieldDefinition $definition, Request $request): Response
    {
        $fieldType = $definition?->getType() ?? (string) $request->query->get('type', 'text');

        if (!$this->fieldTypes->has($fieldType)) {
            $fieldType = 'text';
        }

        return $this->render('@ShowcaseModule/admin/fields/form.html.twig', [
            'type' => $type,
            'definition' => $definition,
            'fieldType' => $fieldType,
            'typeChoices' => $this->fieldTypes->choices(),
            'settingsSchema' => $this->fieldTypes->get($fieldType)->settingsSchema(),
            'error' => $request->query->get('error'),
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    private function backToForm(ShowcaseType $type, ?FieldDefinition $definition, string $errorKey): Response
    {
        $route = $definition !== null ? 'admin_showcase_fields_edit' : 'admin_showcase_fields_new';
        $params = ['machineName' => $type->getMachineName(), 'error' => $this->translator->trans($errorKey)];

        if ($definition !== null) {
            $params['name'] = $definition->getName();
        }

        return $this->redirectToRoute($route, $params);
    }

    private function parseCardinality(string $raw): int
    {
        $raw = trim($raw);

        if ($raw === '' || strtolower($raw) === 'unlimited' || $raw === '-1') {
            return FieldDefinition::UNLIMITED;
        }

        return max(1, (int) $raw);
    }

    private function findTypeOrFail(string $machineName): ShowcaseType
    {
        $type = $this->types->findOneByMachineName($machineName);

        if (!$type instanceof ShowcaseType) {
            throw new NotFoundHttpException($this->translator->trans('showcase.types.error.not_found'));
        }

        return $type;
    }

    private function findFieldOrFail(ShowcaseType $type, string $name): FieldDefinition
    {
        $definition = $this->definitions->findOneByBundleAndName($type->fieldBundle(), $name);

        if ($definition === null) {
            throw new NotFoundHttpException($this->translator->trans('showcase.fields.error.not_found'));
        }

        return $definition;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }
    }
}
