<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumUserRank;
use Modules\Forum\Repository\ForumUserRankRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio ranks and badges. Member bans live on the People screen.
 */
#[Route('/admin/forum/ranks', name: 'admin_forum_ranks_')]
#[IsGranted('forum.ranks.manage')]
final class ForumRankAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumUserRankRepository $rankRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@ForumModule/admin/ranks/index.html.twig', [
            'ranks' => $this->rankRepository->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $rank = new ForumUserRank(
                trim((string) $request->request->get('label')),
                (string) $request->request->get('color', '#8B9DAF'),
                $this->parseMinPosts($request),
            );
            $rank->setIcon($this->parseIcon($request));
            $rank->setSortOrder($request->request->getInt('sort_order'));

            if ($rank->getLabel() === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.ranks.label_required'));
            }

            $this->entityManager->persist($rank);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.ranks.created', ['label' => $rank->getLabel()]));

            return $this->redirectToRoute('admin_forum_ranks_index');
        }

        return $this->render('@ForumModule/admin/ranks/form.html.twig', [
            'rank' => null,
            'formValues' => ['label' => '', 'color' => '#8B9DAF', 'icon' => '', 'minPosts' => '', 'sortOrder' => 0],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $rank = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $label = trim((string) $request->request->get('label'));
            if ($label === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.ranks.label_required'));
            }

            $rank->setLabel($label);
            $rank->setColor((string) $request->request->get('color', $rank->getColor()));
            $rank->setIcon($this->parseIcon($request));
            $rank->setMinPosts($this->parseMinPosts($request));
            $rank->setSortOrder($request->request->getInt('sort_order'));
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.ranks.updated'));

            return $this->redirectToRoute('admin_forum_ranks_index');
        }

        return $this->render('@ForumModule/admin/ranks/form.html.twig', [
            'rank' => $rank,
            'formValues' => [
                'label' => $rank->getLabel(),
                'color' => $rank->getColor(),
                'icon' => $rank->getIcon() ?? '',
                'minPosts' => $rank->getMinPosts(),
                'sortOrder' => $rank->getSortOrder(),
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $rank = $this->findOrFail($id);
        $this->assertValidCsrf($request);

        $this->entityManager->remove($rank);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.forum.ranks.deleted'));

        return $this->redirectToRoute('admin_forum_ranks_index');
    }

    private function parseMinPosts(Request $request): ?int
    {
        $raw = trim((string) $request->request->get('min_posts'));

        return $raw === '' ? null : max(0, (int) $raw);
    }

    private function parseIcon(Request $request): ?string
    {
        $icon = trim((string) $request->request->get('icon'));

        return $icon !== '' ? $icon : null;
    }

    private function findOrFail(int $id): ForumUserRank
    {
        $rank = $this->rankRepository->find($id);
        if (!$rank instanceof ForumUserRank) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.ranks.not_found'));
        }

        return $rank;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_rank', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
