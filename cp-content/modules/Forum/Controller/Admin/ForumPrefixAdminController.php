<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopicPrefix;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicPrefixRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum/prefixes', name: 'admin_forum_prefixes_')]
#[IsGranted('forum.prefixes.manage')]
final class ForumPrefixAdminController extends AbstractController
{
    public function __construct(
        private readonly ForumTopicPrefixRepository $prefixRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.forums_prefixes', icon: 'heroicons:tag', panel: 'studio', priority: 27, capability: 'forum.prefixes.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
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
            $prefix->setCssClass(trim((string) $request->request->get('css_class')));
            $this->syncPrefixSections($prefix, $request);

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
            'boards' => $this->hierarchyService->getTopicBoards($this->localeProvider->getDefaultCode()),
            'selectedSectionIds' => [],
            'formValues' => ['label' => '', 'color' => '#4AADE4', 'sortOrder' => 0, 'cssClass' => ''],
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
            $prefix->setCssClass(trim((string) $request->request->get('css_class')));
            $this->syncPrefixSections($prefix, $request);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('studio.forum.prefixes.updated'));

            return $this->redirectToRoute('admin_forum_prefixes_index');
        }

        $selected = [];
        foreach ($prefix->getSections() as $section) {
            $selected[] = $section->getId();
        }

        return $this->render('@ForumModule/admin/prefixes/form.html.twig', [
            'prefix' => $prefix,
            'boards' => $this->hierarchyService->getTopicBoards($this->localeProvider->getDefaultCode()),
            'selectedSectionIds' => $selected,
            'formValues' => [
                'label' => $prefix->getLabel(),
                'color' => $prefix->getColor(),
                'sortOrder' => $prefix->getSortOrder(),
                'cssClass' => $prefix->getCssClass() ?? '',
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

    private function syncPrefixSections(ForumTopicPrefix $prefix, Request $request): void
    {
        $prefix->clearSections();
        $ids = array_values(array_filter(
            array_map('intval', (array) $request->request->all('section_ids')),
            static fn (int $id): bool => $id > 0,
        ));

        foreach ($ids as $id) {
            $section = $this->sectionRepository->find($id);
            if ($section instanceof ForumSection && $section->allowsTopics()) {
                $prefix->addSection($section);
            }
        }
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
