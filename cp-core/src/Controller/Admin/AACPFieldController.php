<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Entity\EntityTypeRegistry;
use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\ReferenceTargetResolver;
use App\Core\Field\Repository\FieldDefinitionRepository;
use App\Core\Module\ModuleContributionCatalog;
use App\Core\Taxonomy\VocabularyRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Runtime field management for content bundles (Node::type). Same plain-Twig
 * POST-redirect-GET convention as AACPWebhookController. Field name and type are
 * immutable once created — rename/retype = delete + recreate.
 */
final class AACPFieldController
{
    private const BUNDLE_PATTERN = '/^[a-z][a-z0-9_-]{0,49}$/';

    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly FieldDefinitionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FieldDefinitionRegistry $registry,
        private readonly FieldTypeRegistry $types,
        private readonly ModuleContributionCatalog $contributions,
        private readonly ReferenceTargetResolver $referenceResolver,
        private readonly EntityTypeRegistry $entityTypes,
        private readonly VocabularyRegistry $vocabularies,
    ) {
    }

    #[Route('/aacp/fields', name: 'aacp_fields', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.fields', icon: 'heroicons:rectangle-stack', panel: 'aacp', priority: 20, capability: 'system.fields.manage', parent: 'aacp_tools')]
    #[IsGranted('system.fields.manage')]
    public function index(): Response
    {
        $counts = $this->repository->countPerBundle();
        $bundles = [];
        foreach (array_keys($this->knownBundles() + $counts) as $bundle) {
            $bundles[$bundle] = [
                'label' => $this->knownBundles()[$bundle] ?? $bundle,
                'count' => $counts[$bundle] ?? 0,
            ];
        }
        ksort($bundles);

        return new Response($this->twig->render('aacp/fields/index.html.twig', [
            'bundles' => $bundles,
            'csrf_token' => $this->token(),
        ]));
    }

    #[Route('/aacp/fields/{bundle}', name: 'aacp_fields_bundle', methods: ['GET'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    #[IsGranted('system.fields.manage')]
    public function bundle(string $bundle): Response
    {
        return new Response($this->twig->render('aacp/fields/bundle.html.twig', [
            'bundle' => $bundle,
            'bundleLabel' => $this->knownBundles()[$bundle] ?? $bundle,
            'fields' => $this->repository->findByBundle($bundle),
            'typeChoices' => $this->types->choices(),
            'csrf_token' => $this->token(),
        ]));
    }

    #[Route('/aacp/fields/{bundle}/new', name: 'aacp_fields_new', methods: ['GET'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    #[IsGranted('system.fields.manage')]
    public function new(string $bundle, Request $request): Response
    {
        return new Response($this->renderForm($bundle, null, $request));
    }

    #[Route('/aacp/fields/{bundle}/{name}/edit', name: 'aacp_fields_edit', methods: ['GET'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}', 'name' => '[a-z][a-z0-9_]{0,62}'])]
    #[IsGranted('system.fields.manage')]
    public function edit(string $bundle, string $name, Request $request): Response
    {
        return new Response($this->renderForm($bundle, $this->findOrFail($bundle, $name), $request));
    }

    #[Route('/aacp/fields/save', name: 'aacp_fields_save', methods: ['POST'])]
    #[IsGranted('system.fields.manage')]
    public function save(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $bundle = trim((string) $request->request->get('bundle'));
        $name = trim((string) $request->request->get('name'));
        $type = trim((string) $request->request->get('type'));

        if (preg_match(self::BUNDLE_PATTERN, $bundle) !== 1) {
            throw new BadRequestHttpException('Invalid bundle.');
        }

        $existing = $this->repository->findOneByBundleAndName($bundle, $name);

        if ($existing === null) {
            if (preg_match(FieldDefinition::NAME_PATTERN, $name) !== 1) {
                return $this->backToForm($bundle, null, 'aacp.fields.error.bad_name');
            }
            if (!$this->types->has($type)) {
                return $this->backToForm($bundle, null, 'aacp.fields.error.bad_type');
            }
            $definition = new FieldDefinition($bundle, $name, $type, $name);
            $this->entityManager->persist($definition);
        } else {
            $definition = $existing;
            $type = $definition->getType(); // immutable
        }

        $label = trim((string) $request->request->get('label'));
        $definition
            ->setLabel($label !== '' ? mb_substr($label, 0, 191) : ucfirst(str_replace('_', ' ', $name)))
            ->setHelp((string) $request->request->get('help'))
            ->setRequired($request->request->get('required') !== null)
            ->setTranslatable($request->request->get('translatable') !== null)
            ->setQueryable($request->request->get('queryable') !== null)
            ->setFieldGroup((string) $request->request->get('field_group'))
            ->setWeight((int) $request->request->get('weight'))
            ->setCardinality($this->parseCardinality((string) $request->request->get('cardinality')))
            ->setViewCapability((string) $request->request->get('view_capability') ?: null)
            ->setEditCapability((string) $request->request->get('edit_capability') ?: null);

        /** @var array<string, mixed> $rawSettings */
        $rawSettings = (array) $request->request->all('settings');
        $definition->setSettings($this->types->get($type)->normalizeSettings($rawSettings));

        $this->entityManager->flush();
        $this->registry->invalidate($bundle);

        return new RedirectResponse('/aacp/fields/'.$bundle);
    }

    #[Route('/aacp/fields/{bundle}/{name}/delete', name: 'aacp_fields_delete', methods: ['POST'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}', 'name' => '[a-z][a-z0-9_]{0,62}'])]
    #[IsGranted('system.fields.manage')]
    public function delete(string $bundle, string $name, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $this->entityManager->remove($this->findOrFail($bundle, $name));
        $this->entityManager->flush();
        $this->registry->invalidate($bundle);

        return new RedirectResponse('/aacp/fields/'.$bundle);
    }

    #[Route('/aacp/fields/{bundle}/reorder', name: 'aacp_fields_reorder', methods: ['POST'], requirements: ['bundle' => '[a-z][a-z0-9_-]{0,49}'])]
    #[IsGranted('system.fields.manage')]
    public function reorder(string $bundle, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        /** @var array<string, mixed> $order name => weight */
        $order = (array) $request->request->all('weight');
        foreach ($this->repository->findByBundle($bundle) as $definition) {
            if (\array_key_exists($definition->getName(), $order)) {
                $definition->setWeight((int) $order[$definition->getName()]);
            }
        }
        $this->entityManager->flush();
        $this->registry->invalidate($bundle);

        return new RedirectResponse('/aacp/fields/'.$bundle);
    }

    private function renderForm(string $bundle, ?FieldDefinition $definition, Request $request): string
    {
        $type = $definition?->getType() ?? (string) $request->query->get('type', 'text');
        if (!$this->types->has($type)) {
            $type = 'text';
        }

        return $this->twig->render('aacp/fields/form.html.twig', [
            'bundle' => $bundle,
            'definition' => $definition,
            'type' => $type,
            'typeChoices' => $this->types->choices(),
            'settingsSchema' => $this->types->get($type)->settingsSchema(),
            'referenceTargets' => $this->referenceResolver->allowedTargets(array_keys($this->knownBundles())),
            'error' => $request->query->get('error'),
            'csrf_token' => $this->token(),
        ]);
    }

    private function backToForm(string $bundle, ?FieldDefinition $definition, string $errorKey): RedirectResponse
    {
        $url = $definition !== null
            ? '/aacp/fields/'.$bundle.'/'.$definition->getName().'/edit'
            : '/aacp/fields/'.$bundle.'/new';

        return new RedirectResponse($url.'?error='.rawurlencode($this->translator->trans($errorKey)));
    }

    private function parseCardinality(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'unlimited' || $raw === '-1') {
            return FieldDefinition::UNLIMITED;
        }

        return max(1, (int) $raw);
    }

    /**
     * @return array<string, string> bundle => label
     */
    private function knownBundles(): array
    {
        $labels = $this->contributions->studioTypeLabels();
        foreach (array_keys($this->contributions->queryableFields()) as $type) {
            $labels[$type] ??= $type;
        }

        // Single-bundle fieldable entity types (user, and module entities that opt
        // in via #[CpEntityType]). Bundleable types like Node expose their bundles
        // through the studio type labels above, not here.
        foreach ($this->entityTypes->fieldable() as $definition) {
            if (!$definition->bundleable) {
                $labels[$definition->id] ??= $definition->label;
            }
        }

        foreach ($this->vocabularies->all() as $machineName => $row) {
            $labels[$machineName] ??= (string) ($row['label'] ?? $machineName);
        }

        return $labels;
    }

    private function findOrFail(string $bundle, string $name): FieldDefinition
    {
        $definition = $this->repository->findOneByBundleAndName($bundle, $name);
        if ($definition === null) {
            throw new NotFoundHttpException($this->translator->trans('aacp.fields.error.not_found'));
        }

        return $definition;
    }

    private function token(): string
    {
        return $this->csrfTokenManager->getToken('aacp_fields')->getValue();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_fields', (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.fields.error.invalid_csrf'));
        }
    }
}
