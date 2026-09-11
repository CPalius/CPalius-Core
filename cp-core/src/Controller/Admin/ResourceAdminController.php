<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Database\Traits\SoftDeletableTrait;
use App\Core\Pagination\Paginator;
use App\Core\Resource\Admin\ResourceFieldResolver;
use App\Core\Resource\Admin\ResourceFormBuilder;
use App\Core\Resource\ResourceDefinition;
use App\Core\Resource\ResourceRegistry;
use App\Core\Workflow\Exception\WorkflowException;
use App\Core\Workflow\WorkflowManager;
use App\Core\Workflow\WorkflowRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Auto-generated CRUD for every #[CpResource] with a name (Manifesto Law 4.1).
 * Capabilities ({name}.view|create|edit|delete) gate each action; TenantFilter
 * scopes multi-tenant resources automatically; the form is a strict allowlist.
 */
final class ResourceAdminController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
        private readonly ResourceRegistry $resources,
        private readonly ResourceFieldResolver $fields,
        private readonly ResourceFormBuilder $formBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly Paginator $paginator,
        private readonly Security $security,
        private readonly WorkflowManager $workflows,
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly \App\Core\Audit\Repository\AuditLogRepository $audit,
    ) {
    }

    #[Route('/aacp/resources', name: 'aacp_resources', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.resources', icon: 'heroicons:table-cells', panel: 'aacp', priority: 15, capability: 'system.aacp.access', parent: 'aacp_tools')]
    #[IsGranted('system.aacp.access')]
    public function landing(): Response
    {
        $items = [];
        foreach ($this->resources->all() as $definition) {
            if ($definition->name === '' || !$this->security->isGranted($definition->name.'.view')) {
                continue;
            }
            $items[] = $definition;
        }
        usort($items, static fn (ResourceDefinition $a, ResourceDefinition $b): int => strcmp($a->name, $b->name));

        return new Response($this->twig->render('aacp/resources/landing.html.twig', ['resources' => $items]));
    }

    #[Route('/aacp/resources/{name}', name: 'aacp_resource_index', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}'])]
    public function index(string $name, Request $request): Response
    {
        $definition = $this->require($name, 'view');

        $columns = $this->fields->listColumns($definition->entityClass);
        $sortable = array_map(static fn ($c) => $c->property, array_filter($columns, static fn ($c) => $c->sortable));
        $searchable = array_map(static fn ($c) => $c->property, array_filter($columns, static fn ($c) => $c->searchable));

        $sort = \in_array((string) $request->query->get('sort'), $sortable, true) ? (string) $request->query->get('sort') : null;
        $dir = strtolower((string) $request->query->get('dir')) === 'asc' ? 'ASC' : 'DESC';
        $q = trim((string) $request->query->get('q'));
        $page = max(1, $request->query->getInt('page', 1));

        $qb = $this->entityManager->createQueryBuilder()->select('e')->from($definition->entityClass, 'e');

        if ($q !== '' && $searchable !== []) {
            $or = $qb->expr()->orX();
            foreach (array_values($searchable) as $i => $property) {
                $or->add(sprintf('e.%s LIKE :q%d', $property, $i));
                $qb->setParameter('q'.$i, '%'.$q.'%');
            }
            $qb->andWhere($or);
        }

        $identifier = $this->entityManager->getClassMetadata($definition->entityClass)->getSingleIdentifierFieldName();
        $qb->orderBy('e.'.($sort ?? $identifier), $sort !== null ? $dir : 'DESC');

        $result = $this->paginator->paginate($qb, $page, self::PER_PAGE);

        return new Response($this->twig->render('aacp/resources/index.html.twig', [
            'definition' => $definition,
            'columns' => $columns,
            'result' => $result,
            'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'canCreate' => $this->security->isGranted($definition->name.'.create'),
            'canEdit' => $this->security->isGranted($definition->name.'.edit'),
            'canDelete' => $this->security->isGranted($definition->name.'.delete'),
            'identifier' => $identifier,
        ]));
    }

    #[Route('/aacp/resources/{name}/new', name: 'aacp_resource_new', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}'])]
    public function new(string $name): Response
    {
        $definition = $this->require($name, 'create');
        $entity = $this->blankEntity($definition->entityClass);

        return $this->renderForm($definition, $entity, $this->formBuilder->build($entity, $definition)->createView(), null);
    }

    #[Route('/aacp/resources/{name}/{id}', name: 'aacp_resource_show', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}', 'id' => '\d+'])]
    public function show(string $name, int $id): Response
    {
        $definition = $this->require($name, 'edit');
        $entity = $this->loadOrFail($definition, $id);

        return $this->renderForm($definition, $entity, $this->formBuilder->build($entity, $definition)->createView(), $id);
    }

    #[Route('/aacp/resources/{name}/save', name: 'aacp_resource_save', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}'])]
    public function save(string $name, Request $request): Response
    {
        $definition = $this->resource($name);
        $id = $request->request->getInt('id') ?: null;

        $this->assertGranted($definition, $id === null ? 'create' : 'edit');

        $entity = $id === null ? $this->blankEntity($definition->entityClass) : $this->loadOrFail($definition, $id);
        $form = $this->formBuilder->build($entity, $definition);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderForm($definition, $entity, $form->createView(), $id, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($id === null) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return new RedirectResponse('/aacp/resources/'.$name.'/'.$entity->getId().'?saved=1');
    }

    #[Route('/aacp/resources/{name}/{id}/delete', name: 'aacp_resource_delete', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}', 'id' => '\d+'])]
    public function delete(string $name, int $id, Request $request): RedirectResponse
    {
        $definition = $this->resource($name);
        $this->assertGranted($definition, 'delete');
        $this->assertCsrf($definition, (string) $request->request->get('_token'));

        $entity = $this->loadOrFail($definition, $id);

        if ($definition->softDeletable && \in_array(SoftDeletableTrait::class, class_uses($entity) ?: [], true) && method_exists($entity, 'softDelete')) {
            $entity->softDelete();
        } else {
            $this->entityManager->remove($entity);
        }
        $this->entityManager->flush();

        return new RedirectResponse('/aacp/resources/'.$name.'?deleted=1');
    }

    #[Route('/aacp/resources/{name}/{id}/transition/{transition}', name: 'aacp_resource_transition', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]{0,62}', 'id' => '\d+', 'transition' => '[a-z][a-z0-9_]{0,49}'])]
    public function transition(string $name, int $id, string $transition, Request $request): RedirectResponse
    {
        $definition = $this->resource($name);
        $this->assertGranted($definition, 'edit');
        $this->assertCsrf($definition, (string) $request->request->get('_token'));

        if ($definition->workflow === null || $definition->workflow === '') {
            throw new NotFoundHttpException();
        }

        $entity = $this->loadOrFail($definition, $id);
        $comment = trim((string) $request->request->get('comment')) ?: null;
        $by = $this->security->getUser();

        try {
            $this->workflows->apply($entity, $definition->workflow, $transition, $comment, $by instanceof User ? $by : null);
        } catch (WorkflowException $e) {
            return new RedirectResponse('/aacp/resources/'.$name.'/'.$id.'?error='.rawurlencode($e->getMessage()));
        }
        $this->entityManager->flush();

        return new RedirectResponse('/aacp/resources/'.$name.'/'.$id.'?transitioned=1');
    }

    private function renderForm(ResourceDefinition $definition, object $entity, $formView, ?int $id, int $status = Response::HTTP_OK): Response
    {
        $transitions = [];
        $workflow = null;
        if ($id !== null && $definition->workflow !== null && $definition->workflow !== '') {
            $workflow = $this->workflowRegistry->get($definition->workflow);
            $transitions = $this->workflows->enabledTransitions($entity, $definition->workflow);
        }

        $history = ($id !== null && $definition->auditable)
            ? $this->audit->findForResource($definition->name, (string) $id, 20)
            : [];

        return new Response($this->twig->render('aacp/resources/form.html.twig', [
            'definition' => $definition,
            'entity' => $entity,
            'form' => $formView,
            'id' => $id,
            'workflow' => $workflow,
            'transitions' => $transitions,
            'marking' => $workflow !== null ? $this->workflows->getMarking($entity, $definition->workflow) : null,
            'history' => $history,
            'csrf_token' => $this->csrf->getToken('resource_'.$definition->name)->getValue(),
        ]), $status);
    }

    private function require(string $name, string $capability): ResourceDefinition
    {
        $definition = $this->resource($name);
        $this->assertGranted($definition, $capability);

        return $definition;
    }

    private function resource(string $name): ResourceDefinition
    {
        $definition = $this->resources->getByName($name);
        if ($definition === null || $definition->name === '') {
            throw new NotFoundHttpException();
        }

        return $definition;
    }

    private function assertGranted(ResourceDefinition $definition, string $capability): void
    {
        if (!\in_array($capability, $definition->capabilities, true) || !$this->security->isGranted($definition->name.'.'.$capability)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function assertCsrf(ResourceDefinition $definition, string $token): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('resource_'.$definition->name, $token))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.resources.invalid_csrf'));
        }
    }

    private function loadOrFail(ResourceDefinition $definition, int $id): object
    {
        $entity = $this->entityManager->find($definition->entityClass, $id);
        if ($entity === null) {
            throw new NotFoundHttpException();
        }

        return $entity;
    }

    private function blankEntity(string $entityClass): object
    {
        try {
            return new $entityClass();
        } catch (\ArgumentCountError|\Error) {
            return (new \ReflectionClass($entityClass))->newInstanceWithoutConstructor();
        }
    }
}
