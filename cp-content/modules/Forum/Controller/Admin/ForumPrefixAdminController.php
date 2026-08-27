<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\ForumTopicPrefix;
use App\Repository\ForumTopicPrefixRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum/prefixes', name: 'admin_forum_prefixes_')]
#[IsGranted('forum.section.manage')]
final class ForumPrefixAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumTopicPrefixRepository $prefixRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Konu Ön Ekleri', icon: 'heroicons:tag', panel: 'studio', priority: 27, capability: 'forum.section.manage', group: 'İçerik', parent: 'admin_forum_dashboard')]
    public function index(): Response
    {
        return $this->render('@ForumModule/admin/prefixes/index.html.twig', [
            'prefixes' => $this->prefixRepository->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $prefix = new ForumTopicPrefix(
                trim((string) $request->request->get('label')),
                (string) $request->request->get('color', '#4AADE4'),
            );
            $prefix->setSortOrder($request->request->getInt('sort_order'));

            if ($prefix->getLabel() === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.prefixes.label_required'));
            }

            $this->entityManager->persist($prefix);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.prefixes.created', ['label' => $prefix->getLabel()]));

            return $this->redirectToRoute('admin_forum_prefixes_index');
        }

        return $this->render('@ForumModule/admin/prefixes/form.html.twig', [
            'prefix' => null,
            'formValues' => ['label' => '', 'color' => '#4AADE4', 'sortOrder' => 0],
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $prefix = $this->findOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request);

            $label = trim((string) $request->request->get('label'));
            if ($label === '') {
                throw new BadRequestHttpException($this->translator->trans('studio.forum.prefixes.label_required'));
            }

            $prefix->setLabel($label);
            $prefix->setColor((string) $request->request->get('color', $prefix->getColor()));
            $prefix->setSortOrder($request->request->getInt('sort_order'));
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.prefixes.updated'));

            return $this->redirectToRoute('admin_forum_prefixes_index');
        }

        return $this->render('@ForumModule/admin/prefixes/form.html.twig', [
            'prefix' => $prefix,
            'formValues' => [
                'label' => $prefix->getLabel(),
                'color' => $prefix->getColor(),
                'sortOrder' => $prefix->getSortOrder(),
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $prefix = $this->findOrFail($id);
        $this->assertValidCsrf($request);

        $this->entityManager->remove($prefix);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('studio.forum.prefixes.deleted'));

        return $this->redirectToRoute('admin_forum_prefixes_index');
    }

    private function findOrFail(int $id): ForumTopicPrefix
    {
        $prefix = $this->prefixRepository->find($id);
        if (!$prefix instanceof ForumTopicPrefix) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.prefixes.not_found'));
        }

        return $prefix;
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forum_prefix', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
