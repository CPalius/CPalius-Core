<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\ForumPostReport;
use App\Entity\ForumSection;
use App\Entity\User;
use App\Core\Pagination\Paginator;
use App\Entity\ForumTopic;
use App\Repository\ForumPostReportRepository;
use App\Repository\ForumPostRepository;
use App\Repository\ForumSectionRepository;
use App\Repository\ForumTopicPrefixRepository;
use App\Repository\ForumTopicRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\ForumSectionType;
use Modules\Forum\Service\ForumModerationService;
use Modules\Forum\Service\ForumSectionDeletionService;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Modules\Forum\Service\ForumStatsService;
use Modules\Forum\Service\ForumTopicService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/forum', name: 'admin_forum_')]
final class ForumAdminController extends AbstractController
{
    private const DEFAULT_LOCALE = 'tr';

    public function __construct(
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumTopicPrefixRepository $topicPrefixRepository,
        private readonly ForumPostReportRepository $postReportRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumStatsService $statsService,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly ForumSectionDeletionService $deletionService,
        private readonly ForumTopicService $topicService,
        private readonly ForumModerationService $moderationService,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'Forum', icon: 'heroicons:chat-bubble-left-right', panel: 'studio', priority: 25, capability: 'forum.section.manage', group: 'İçerik')]
    #[IsGranted('forum.section.manage')]
    public function dashboard(): Response
    {
        $sections = $this->hierarchyService->getAllSections(self::DEFAULT_LOCALE);
        $latestTopics = $this->topicRepository->findLatest(10);

        $totalTopics = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from('App\Entity\ForumTopic', 't')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPosts = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from('App\Entity\ForumPost', 'p')
            ->getQuery()
            ->getSingleScalarResult();

        // Grafikler için: en aktif 6 (konteyner olmayan) bölüm, mesaj sayısına göre.
        $chartSections = array_values(array_filter($sections, static fn (ForumSection $s) => !$s->isContainer()));
        usort($chartSections, static fn (ForumSection $a, ForumSection $b) => $b->getPostCount() <=> $a->getPostCount());
        $chartSections = \array_slice($chartSections, 0, 6);

        $openReportCount = $this->postReportRepository->countOpen();
        $totalReportCount = $this->postReportRepository->countTotal();

        return $this->render('@ForumModule/admin/dashboard.html.twig', [
            'sections' => $sections,
            'latestTopics' => $latestTopics,
            'totalTopics' => $totalTopics,
            'totalPosts' => $totalPosts,
            'totalMembers' => $this->postRepository->countDistinctAuthors(),
            'latestPosts' => $this->postRepository->findLatest(8),
            'latestMembers' => $this->userRepository->createAdminListQueryBuilder()->setMaxResults(8)->getQuery()->getResult(),
            'openReportCount' => $openReportCount,
            'totalReportCount' => $totalReportCount,
            'chartSectionLabels' => array_map(static fn (ForumSection $s) => $s->getTitle(), $chartSections),
            'chartSectionPostCounts' => array_map(static fn (ForumSection $s) => $s->getPostCount(), $chartSections),
            'chartSectionTopicCounts' => array_map(static fn (ForumSection $s) => $s->getTopicCount(), $chartSections),
        ]);
    }

    #[Route('/moderation', name: 'moderation_index', methods: ['GET'])]
    #[IsGranted('forum.topic.moderate')]
    public function moderationIndex(): Response
    {
        return $this->render('@ForumModule/admin/moderation/index.html.twig', [
            'reports' => $this->postReportRepository->findOpen(),
        ]);
    }

    #[Route('/moderation/{id}/resolve', name: 'moderation_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.topic.moderate')]
    public function moderationResolve(int $id, Request $request): Response
    {
        $report = $this->findReportOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_moderation');

        /** @var User $user */
        $user = $this->getUser();
        $this->moderationService->resolve($report, $user);
        $this->addFlash('success', $this->translator->trans('studio.forum.moderation.report_resolved'));

        return $this->redirectToRoute('admin_forum_moderation_index');
    }

    #[Route('/moderation/{id}/dismiss', name: 'moderation_dismiss', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.topic.moderate')]
    public function moderationDismiss(int $id, Request $request): Response
    {
        $report = $this->findReportOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_moderation');

        /** @var User $user */
        $user = $this->getUser();
        $this->moderationService->dismiss($report, $user);
        $this->addFlash('success', $this->translator->trans('studio.forum.moderation.report_dismissed'));

        return $this->redirectToRoute('admin_forum_moderation_index');
    }

    #[Route('/moderation/{id}/delete-post', name: 'moderation_delete_post', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.topic.moderate')]
    public function moderationDeletePost(int $id, Request $request): Response
    {
        $report = $this->findReportOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_moderation');

        /** @var User $user */
        $user = $this->getUser();
        $this->topicService->deletePost($report->getPost());
        $this->moderationService->resolve($report, $user);
        $this->addFlash('success', $this->translator->trans('studio.forum.moderation.post_deleted_report_closed'));

        return $this->redirectToRoute('admin_forum_moderation_index');
    }

    private function findReportOrFail(int $id): ForumPostReport
    {
        $report = $this->postReportRepository->find($id);
        if (!$report instanceof ForumPostReport) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.moderation.report_not_found'));
        }

        return $report;
    }

    #[Route('/sections', name: 'sections_index', methods: ['GET'])]
    #[IsGranted('forum.section.manage')]
    public function sectionsIndex(): Response
    {
        return $this->render('@ForumModule/admin/sections/index.html.twig', [
            'tree' => $this->hierarchyService->buildAdminTree(self::DEFAULT_LOCALE),
        ]);
    }

    #[Route('/sections/create', name: 'sections_create', methods: ['GET', 'POST'])]
    #[IsGranted('forum.section.manage')]
    public function sectionsCreate(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_forum_section');

            $section = $this->buildSectionFromRequest($request);
            $this->entityManager->persist($section);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('studio.forum.sections.created', ['title' => $section->getTitle()]));

            return $this->redirectToRoute('admin_forum_sections_index');
        }

        return $this->renderSectionForm(null, $this->defaultFormValues($request));
    }

    #[Route('/sections/{id}/edit', name: 'sections_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.section.manage')]
    public function sectionsEdit(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_forum_section');
            $this->applyRequestToSection($section, $request);
            $section->touch();
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('studio.forum.sections.updated', ['title' => $section->getTitle()]));

            return $this->redirectToRoute('admin_forum_sections_index');
        }

        return $this->renderSectionForm($section, [
            'title' => $section->getTitle(),
            'code' => $section->getCode(),
            'slug' => $section->getSlug(),
            'description' => $section->getDescription() ?? '',
            'icon' => $section->getIcon() ?? '',
            'parentId' => $section->getParent()?->getId(),
            'sortOrder' => $section->getSortOrder(),
            'sectionType' => $section->getSectionType()->value,
        ]);
    }

    #[Route('/sections/{id}/delete', name: 'sections_delete', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.section.manage')]
    public function sectionsDelete(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $impact = $this->deletionService->analyze($section);

        if ($request->isMethod('GET')) {
            return $this->render('@ForumModule/admin/sections/delete.html.twig', [
                'section' => $section,
                'impact' => $impact,
            ]);
        }

        $this->assertValidCsrf($request, 'admin_forum_section_delete');

        $errorKey = $this->deletionService->validateRequest(
            $section,
            $impact,
            $request->request->getBoolean('confirm_delete'),
            trim((string) $request->request->get('confirm_title', '')),
        );

        if ($errorKey !== null) {
            $this->addFlash('error', $this->translator->trans($errorKey, [
                '%count%' => $impact->childSectionCount,
                '%topics%' => $impact->topicCount,
                '%posts%' => $impact->postCount,
            ]));

            return $this->render('@ForumModule/admin/sections/delete.html.twig', [
                'section' => $section,
                'impact' => $impact,
            ]);
        }

        $title = $section->getTitle();
        $this->deletionService->delete($section);
        $this->addFlash('success', $this->translator->trans('studio.forum.sections.deleted_named', ['title' => $title]));

        return $this->redirectToRoute('admin_forum_sections_index');
    }

    #[Route('/sections/{id}/resync', name: 'sections_resync', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.section.manage')]
    public function sectionsResync(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_section');
        $this->statsService->syncSection($section);
        $this->addFlash('success', $this->translator->trans('studio.forum.sections.resynced'));

        return $this->redirectToRoute('admin_forum_sections_index');
    }

    #[Route('/topics', name: 'topics_index', methods: ['GET'])]
    #[CpAdminMenu(label: 'Konular', icon: 'heroicons:chat-bubble-left-right', panel: 'studio', priority: 26, capability: 'forum.topic.moderate', group: 'İçerik', parent: 'admin_forum_dashboard')]
    #[IsGranted('forum.topic.moderate')]
    public function topicsIndex(Request $request): Response
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(ForumTopic::class, 't')
            ->orderBy('t.sticky', 'DESC')
            ->addOrderBy('t.updatedAt', 'DESC');

        $search = trim((string) $request->query->get('q', ''));
        if ($search !== '') {
            $qb->andWhere('t.title LIKE :search')->setParameter('search', '%' . $search . '%');
        }

        $topics = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 30);

        return $this->render('@ForumModule/admin/topics/index.html.twig', [
            'topics' => $topics,
            'search' => $search,
            'prefixes' => $this->topicPrefixRepository->findAllOrdered(),
        ]);
    }

    #[Route('/topics/{id}/delete', name: 'topics_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.topic.moderate')]
    public function topicsDelete(int $id, Request $request): Response
    {
        $topic = $this->topicRepository->find($id);
        if ($topic === null) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.topics.not_found'));
        }

        $this->assertValidCsrf($request, 'admin_forum_topic');
        $sectionSlug = $topic->getSection()->getSlug();
        $this->topicService->deleteTopic($topic);
        $this->addFlash('success', $this->translator->trans('studio.forum.topics.deleted'));

        return $this->redirectToRoute('admin_forum_sections_index', [], Response::HTTP_SEE_OTHER);
    }

    private function buildSectionFromRequest(Request $request): ForumSection
    {
        $title = trim((string) $request->request->get('title'));
        $code = trim((string) $request->request->get('code'));
        $slug = trim((string) $request->request->get('slug'));

        if ($title === '' || $code === '') {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.sections.title_code_required'));
        }

        if ($slug === '') {
            $slugger = new AsciiSlugger(self::DEFAULT_LOCALE);
            $slug = strtolower($slugger->slug($title)->toString());
        }

        $section = new ForumSection($code, $slug, self::DEFAULT_LOCALE, $title);
        $this->applyRequestToSection($section, $request);

        return $section;
    }

    private function applyRequestToSection(ForumSection $section, Request $request): void
    {
        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $parentId = $request->request->get('parent_id');
        $parentId = $parentId !== null && ctype_digit((string) $parentId) ? (int) $parentId : null;

        $icon = trim((string) $request->request->get('icon'));

        $type = ForumSectionType::tryFrom((string) $request->request->get('section_type', '')) ?? ForumSectionType::Subcategory;
        $parent = $parentId !== null ? $this->sectionRepository->find($parentId) : null;

        $error = $this->hierarchyService->validateParent($type, $parent instanceof ForumSection ? $parent : null);
        if ($error !== null) {
            throw new BadRequestHttpException($error);
        }

        $section->setTitle($title);
        $section->setDescription($description !== '' ? $description : null);
        $section->setIcon($icon !== '' ? $icon : null);
        $section->setSortOrder($request->request->getInt('sort_order'));
        $section->setSectionType($type);
        $section->setParent($parent instanceof ForumSection ? $parent : null);
    }

    /** @return array<string, mixed> */
    private function defaultFormValues(?Request $request = null): array
    {
        $sectionType = ForumSectionType::Division->value;
        $parentId = null;

        if ($request instanceof Request) {
            $fromQuery = ForumSectionType::tryFrom((string) $request->query->get('section_type', ''));
            if ($fromQuery instanceof ForumSectionType) {
                $sectionType = $fromQuery->value;
            }

            $parentIdRaw = $request->query->get('parent_id');
            if ($parentIdRaw !== null && ctype_digit((string) $parentIdRaw)) {
                $parentId = (int) $parentIdRaw;
            }
        }

        return [
            'title' => '',
            'code' => '',
            'slug' => '',
            'description' => '',
            'icon' => '',
            'parentId' => $parentId,
            'sortOrder' => 0,
            'sectionType' => $sectionType,
        ];
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderSectionForm(?ForumSection $section, array $formValues): Response
    {
        return $this->render('@ForumModule/admin/sections/form.html.twig', [
            'section' => $section,
            'parentOptionsByType' => $this->hierarchyService->buildParentOptionsByType(self::DEFAULT_LOCALE, $section),
            'formValues' => $formValues,
        ]);
    }

    private function findSectionOrFail(int $id): ForumSection
    {
        $section = $this->sectionRepository->find($id);
        if (!$section instanceof ForumSection) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.sections.not_found'));
        }

        return $section;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.csrf_invalid'));
        }
    }
}
