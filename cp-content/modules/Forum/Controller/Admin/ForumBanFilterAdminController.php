<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumBanFilter;
use Modules\Forum\ForumBanFilterType;
use Modules\Forum\Repository\ForumBanFilterRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum/filters', name: 'admin_forum_filters_')]
#[IsGranted('forum.moderation.manage')]
final class ForumBanFilterAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumBanFilterRepository $filterRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@ForumModule/admin/filters/index.html.twig', [
            'filters' => $this->filterRepository->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            [$type, $rule, $reason] = $this->parseFields($request);
            if ($this->filterRepository->findOneByTypeAndRule($type, $rule) instanceof ForumBanFilter) {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.filters.already_exists'));
            }

            $filter = new ForumBanFilter($type, $rule, $reason);
            $this->entityManager->persist($filter);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.filters.created'));

            return $this->redirectToRoute('admin_forum_filters_index');
        }

        return $this->renderFilterForm(null, [
            'type' => ForumBanFilterType::Ip->value,
            'rule' => '',
            'reason' => '',
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $filter = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);
            [$type, $rule, $reason] = $this->parseFields($request);
            $existing = $this->filterRepository->findOneByTypeAndRule($type, $rule);
            if ($existing instanceof ForumBanFilter && $existing->getId() !== $filter->getId()) {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.filters.already_exists'));
            }

            $filter->setType($type);
            $filter->setRule($rule);
            $filter->setReason($reason);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.filters.updated'));

            return $this->redirectToRoute('admin_forum_filters_index');
        }

        return $this->renderFilterForm($filter, [
            'type' => $filter->getType()->value,
            'rule' => $filter->getRule(),
            'reason' => $filter->getReason() ?? '',
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $filter = $this->findOrFail($id);
        $this->assertValidCsrf($request);
        $this->entityManager->remove($filter);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.forum.filters.deleted'));

        return $this->redirectToRoute('admin_forum_filters_index');
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderFilterForm(?ForumBanFilter $filter, array $formValues): Response
    {
        return $this->render('@ForumModule/admin/filters/form.html.twig', [
            'filter' => $filter,
            'formValues' => $formValues,
            'types' => ForumBanFilterType::cases(),
        ]);
    }

    /**
     * @return array{0: ForumBanFilterType, 1: string, 2: ?string}
     */
    private function parseFields(Request $request): array
    {
        $type = ForumBanFilterType::tryFrom((string) $request->request->get('type'));
        $rule = trim((string) $request->request->get('rule'));
        $reason = trim((string) $request->request->get('reason'));

        if (!$type instanceof ForumBanFilterType || $rule === '') {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.filters.rule_required'));
        }

        return [$type, $rule, $reason !== '' ? $reason : null];
    }

    private function findOrFail(int $id): ForumBanFilter
    {
        $filter = $this->filterRepository->find($id);
        if (!$filter instanceof ForumBanFilter) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.filters.not_found'));
        }

        return $filter;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_filter', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
