<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\Localization\TranslationGroupResolver;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Pagination\Paginator;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPostReport;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumNodeType;
use Modules\Forum\ForumSectionType;
use Modules\Forum\Navigation\ForumNavigation;
use Modules\Forum\Repository\ForumBanRepository;
use Modules\Forum\Repository\ForumBoardStatsRepository;
use Modules\Forum\Repository\ForumPostReportRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicPrefixRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Service\ForumAccessService;
use Modules\Forum\Service\ForumModerationLogService;
use Modules\Forum\Service\ForumModerationService;
use Modules\Forum\Service\ForumPermissionService;
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
    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumTopicPrefixRepository $topicPrefixRepository,
        private readonly ForumPostReportRepository $postReportRepository,
        private readonly ForumBoardStatsRepository $boardStatsRepository,
        private readonly ForumBanRepository $banRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumStatsService $statsService,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly ForumSectionDeletionService $deletionService,
        private readonly ForumTopicService $topicService,
        private readonly ForumModerationService $moderationService,
        private readonly ForumPermissionService $permissionService,
        private readonly ForumAccessService $accessService,
        private readonly TranslationGroupResolver $translationGroupResolver,
        private readonly OriginCachePurger $originCachePurger,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
        private readonly ForumModerationLogService $moderationLog,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.forums', icon: 'heroicons:chat-bubble-left-right', panel: 'studio', priority: ForumNavigation::OVERVIEW, capability: 'forum.section.manage', group: 'studio.group.content')]
    #[IsGranted('forum.section.manage')]
    public function dashboard(): Response
    {
        $sections = $this->hierarchyService->getAllSections($this->localeProvider->getDefaultCode());
        $boardStats = $this->boardStatsRepository->findOneByLocale($this->localeProvider->getDefaultCode());
        $heldCount = ($boardStats?->getTopicCountHeld() ?? 0) + ($boardStats?->getPostCountHeld() ?? 0);

        return $this->render('@ForumModule/admin/dashboard.html.twig', [
            'sectionCount' => \count($sections),
            'topicCount' => $boardStats?->getTopicCount() ?? 0,
            'postCount' => $boardStats?->getPostCount() ?? 0,
            'heldCount' => $heldCount,
            'lastPosterName' => $boardStats?->getLastPosterName(),
            'lastPostAt' => $boardStats?->getLastPostAt(),
            'openReportCount' => $this->postReportRepository->countOpen(),
            'activeBanCount' => \count($this->banRepository->findActiveAll(80)),
        ]);
    }

    #[Route('/moderation', name: 'moderation_index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.forum.menu.moderation', icon: 'heroicons:shield-check', panel: 'studio', priority: ForumNavigation::MODERATION, capability: 'forum.moderation.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
    #[IsGranted('forum.moderation.manage')]
    public function moderationIndex(): Response
    {
        return $this->render('@ForumModule/admin/moderation/index.html.twig', [
            'reports' => $this->postReportRepository->findOpen(),
            'deletedTopics' => $this->topicRepository->findDeleted(80),
            'bulkTopics' => $this->topicRepository->findLatest(60),
            'moveTargets' => $this->hierarchyService->getTopicBoards($this->localeProvider->getDefaultCode()),
            'heldPosts' => $this->postRepository->findHeld(80),
            'moderationLogs' => $this->moderationLog->latest(40),
        ]);
    }

    #[Route('/moderation/bulk', name: 'moderation_bulk', methods: ['POST'])]
    #[IsGranted('forum.moderation.manage')]
    public function moderationBulk(Request $request): Response
    {
        $this->assertValidCsrf($request, 'admin_forum_moderation');

        $ids = array_values(array_filter(
            array_map('intval', (array) $request->request->all('topic_ids')),
            static fn (int $id): bool => $id > 0,
        ));
        $topics = $this->topicRepository->findByIds($ids);
        $action = (string) $request->request->get('bulk_action');

        match ($action) {
            'lock' => $this->moderationService->bulkLock($topics, true),
            'unlock' => $this->moderationService->bulkLock($topics, false),
            'sticky' => $this->moderationService->bulkSticky($topics, true),
            'unsticky' => $this->moderationService->bulkSticky($topics, false),
            'delete' => $this->moderationService->bulkSoftDelete($topics),
            'hard_delete' => $this->moderationService->bulkHardDelete($topics),
            'restore' => $this->moderationService->bulkRestore($topics),
            'move' => $this->bulkMoveFromRequest($request, $topics),
            'merge' => $this->bulkMergeFromRequest($request, $topics),
            default => throw new BadRequestHttpException($this->translator->trans('site.forum.moderate.invalid_action')),
        };

        $this->addFlash('success', $this->translator->trans('studio.forum.moderation.bulk_done', ['count' => \count($topics)]));
        /** @var User $actor */
        $actor = $this->getUser();
        $this->moderationLog->record($actor, 'bulk_'.$action, 'topic', $ids[0] ?? 0, ['count' => \count($topics), 'ids' => $ids]);

        return $this->redirectToRoute('admin_forum_moderation_index');
    }

    /**
     * @param list<ForumTopic> $topics
     */
    private function bulkMoveFromRequest(Request $request, array $topics): void
    {
        $targetId = $request->request->getInt('target_section_id');
        $target = $this->sectionRepository->find($targetId);
        if (!$target instanceof ForumSection || !$target->allowsTopics()) {
            throw new BadRequestHttpException($this->translator->trans('site.forum.moderate.invalid_target_section'));
        }

        $this->moderationService->bulkMove($topics, $target, $request->request->getBoolean('keep_redirect'));
    }

    /**
     * @param list<ForumTopic> $topics
     */
    private function bulkMergeFromRequest(Request $request, array $topics): void
    {
        $targetId = $request->request->getInt('merge_target_topic_id');
        $target = $this->topicRepository->find($targetId);
        if (!$target instanceof ForumTopic) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.moderation.merge_target_required'));
        }

        $sources = array_values(array_filter(
            $topics,
            static fn (ForumTopic $topic): bool => $topic->getId() !== $target->getId(),
        ));
        if ($sources === []) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.moderation.merge_sources_required'));
        }

        $this->moderationService->mergeInto($sources, $target);
    }

    #[Route('/moderation/{id}/resolve', name: 'moderation_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.moderation.manage')]
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
    #[IsGranted('forum.moderation.manage')]
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
    #[IsGranted('forum.moderation.manage')]
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

    #[Route('/moderation/post/{id}/approve', name: 'moderation_approve_post', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.moderation.manage')]
    public function moderationApprovePost(int $id, Request $request): Response
    {
        $this->assertValidCsrf($request, 'admin_forum_moderation');
        $post = $this->postRepository->find($id);
        if (!$post instanceof \Modules\Forum\Entity\ForumPost) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.moderation.report_not_found'));
        }

        $this->moderationService->approvePost($post);
        /** @var User $actor */
        $actor = $this->getUser();
        $this->moderationLog->record($actor, 'approve', 'post', $id);
        $this->addFlash('success', $this->translator->trans('studio.forum.moderation.approved'));

        return $this->redirectToRoute('admin_forum_moderation_index');
    }

    #[Route('/moderation/post/{id}/reject', name: 'moderation_reject_post', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.moderation.manage')]
    public function moderationRejectPost(int $id, Request $request): Response
    {
        $this->assertValidCsrf($request, 'admin_forum_moderation');
        $post = $this->postRepository->find($id);
        if (!$post instanceof \Modules\Forum\Entity\ForumPost) {
            throw new NotFoundHttpException($this->translator->trans('studio.forum.moderation.report_not_found'));
        }

        $this->moderationService->rejectHeldPost($post);
        /** @var User $actor */
        $actor = $this->getUser();
        $this->moderationLog->record($actor, 'reject', 'post', $id);
        $this->addFlash('success', $this->translator->trans('studio.forum.moderation.rejected'));

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
    #[CpAdminMenu(label: 'studio.forum.menu.structure', icon: 'heroicons:rectangle-group', panel: 'studio', priority: ForumNavigation::STRUCTURE, capability: 'forum.nodes.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsIndex(Request $request): Response
    {
        $locale = $this->resolveLocale($request->query->get('locale'));

        return $this->render('@ForumModule/admin/sections/index.html.twig', [
            'tree' => $this->hierarchyService->buildAdminTree($locale),
            'locales' => $this->localeProvider->getLocales(),
            'currentLocale' => $locale,
        ]);
    }

    #[Route('/sections/create', name: 'sections_create', methods: ['GET', 'POST'])]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsCreate(Request $request): Response
    {
        $bag = $request->isMethod('POST') ? $request->request : $request->query;
        $locale = $this->resolveLocale($bag->get('locale'));
        $source = $this->findTranslationSource($bag->get('translation_of'));

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_forum_section');

            if ($source instanceof ForumSection && $source->getLocale() !== $locale) {
                $existing = $this->translationGroupResolver->findGroup($source)[$locale] ?? null;
                if ($existing instanceof ForumSection) {
                    $this->addFlash('error', $this->translator->trans('studio.forum.sections.error.translation_exists', [
                        'title' => $source->getTitle(),
                        'locale' => $locale,
                    ]));

                    return $this->redirectToRoute('admin_forum_sections_edit', ['id' => $existing->getId()]);
                }
            }

            $section = $this->buildSectionFromRequest($request, $locale, $source);
            $this->entityManager->persist($section);

            if ($source instanceof ForumSection && $source->getLocale() !== $locale) {
                $this->translationGroupResolver->link($source, $section);
            }

            $this->entityManager->flush();
            $this->rebuildPathTree($section);
            $this->entityManager->flush();

            if ($source instanceof ForumSection && $source->getLocale() !== $locale) {
                $this->permissionService->copyToSection($source, $section);
                $this->entityManager->flush();
            }

            $this->originCachePurger->purgeAreas('forums', 'home');

            $this->addFlash('success', $source instanceof ForumSection
                ? $this->translator->trans('cp.translation_tabs.linked_flash', [
                    'name' => $section->getTitle(),
                    'locale' => $locale,
                ])
                : $this->translator->trans('studio.forum.sections.created', ['title' => $section->getTitle()]));

            return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $locale]);
        }

        $formValues = $source instanceof ForumSection
            ? $this->formValuesFromSection($source, $locale)
            : $this->defaultFormValues($request);

        return $this->renderSectionForm(null, $formValues, $locale, $source);
    }

    #[Route('/sections/{id}/edit', name: 'sections_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsEdit(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $locale = $section->getLocale();

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'admin_forum_section');
            $this->applyRequestToSection($section, $request);
            $section->touch();
            $this->entityManager->flush();
            $this->rebuildPathTree($section);
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('forums', 'home');

            $this->addFlash('success', $this->translator->trans('studio.forum.sections.updated', ['title' => $section->getTitle()]));

            return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $locale]);
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
            'nodeType' => $section->getNodeType()->value,
            'linkUrl' => $section->getLinkUrl() ?? '',
            'requiredCapability' => $section->getRequiredCapability() ?? '',
            'locked' => $section->isLocked(),
            'defaultTopicSort' => $section->getDefaultTopicSort(),
            'rulesHtml' => $section->getRulesHtml() ?? '',
            'hasAccessSecret' => $section->isPassworded(),
        ], $locale);
    }

    #[Route('/sections/{id}/delete', name: 'sections_delete', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsDelete(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $locale = $section->getLocale();
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
        $this->originCachePurger->purgeAreas('forums', 'home');
        $this->addFlash('success', $this->translator->trans('studio.forum.sections.deleted_named', ['title' => $title]));

        return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $locale]);
    }

    #[Route('/sections/{id}/resync', name: 'sections_resync', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsResync(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_section');
        $this->statsService->syncSection($section);
        $this->addFlash('success', $this->translator->trans('studio.forum.sections.resynced'));

        return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $section->getLocale()]);
    }

    #[Route('/sections/{id}/move', name: 'sections_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsMove(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_section');

        $direction = (string) $request->request->get('direction');
        $siblings = $this->sectionRepository->findSiblings($section);
        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling->getId() === $section->getId()) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $section->getLocale()]);
        }

        $swapWith = $direction === 'up' ? ($siblings[$index - 1] ?? null) : ($siblings[$index + 1] ?? null);
        if ($swapWith instanceof ForumSection) {
            $currentOrder = $section->getSortOrder();
            $section->setSortOrder($swapWith->getSortOrder());
            $swapWith->setSortOrder($currentOrder);
            $section->touch();
            $swapWith->touch();
            $this->entityManager->flush();
            $this->originCachePurger->purgeAreas('forums', 'home');
        }

        return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $section->getLocale()]);
    }

    #[Route('/sections/{id}/toggle-lock', name: 'sections_toggle_lock', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('forum.nodes.manage')]
    public function sectionsToggleLock(int $id, Request $request): Response
    {
        $section = $this->findSectionOrFail($id);
        $this->assertValidCsrf($request, 'admin_forum_section');
        $section->setLocked(!$section->isLocked());
        $section->touch();
        $this->entityManager->flush();
        // Same delimiter rule as the relative-time filter: "forums" is a plain
        // domain, so the keys have to carry their own %…%. The state was also
        // being pasted in as an untranslated English word.
        $this->addFlash('success', $this->translator->trans('studio.forum.sections.lock_toggled', [
            '%title%' => $section->getTitle(),
            '%state%' => $this->translator->trans(
                $section->isLocked() ? 'studio.forum.sections.state_locked' : 'studio.forum.sections.state_open',
                [],
                'forums',
            ),
        ], 'forums'));

        return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $section->getLocale()]);
    }

    #[Route('/topics', name: 'topics_index', methods: ['GET'])]
    #[IsGranted('forum.topic.moderate')]
    public function topicsIndex(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->topicRepository->createAdminListQueryBuilder($search !== '' ? $search : null);
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

        return $this->redirectToRoute('admin_forum_sections_index', ['locale' => $topic->getSection()->getLocale()], Response::HTTP_SEE_OTHER);
    }

    private function buildSectionFromRequest(Request $request, string $locale, ?ForumSection $source = null): ForumSection
    {
        $title = trim((string) $request->request->get('title'));
        $code = $source instanceof ForumSection
            ? $source->getCode()
            : trim((string) $request->request->get('code'));
        $slug = trim((string) $request->request->get('slug'));

        if ($title === '' || $code === '') {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.sections.title_code_required'));
        }

        if ($this->sectionRepository->findOneByCodeAndLocale($code, $locale) instanceof ForumSection) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.sections.error.code_taken', ['code' => $code, 'locale' => $locale]));
        }

        if ($slug === '') {
            $slugger = new AsciiSlugger($locale);
            $slug = strtolower($slugger->slug($title)->toString());
        }

        $section = new ForumSection($code, $slug, $locale, $title);
        if ($source instanceof ForumSection) {
            $section->setIcon($source->getIcon());
            $section->setSectionType($source->getSectionType());
            $section->setNodeType($source->getNodeType());
            $section->setLinkUrl($source->getLinkUrl() ?? '');
            $section->setRequiredCapability($source->getRequiredCapability());
            $section->setLocked($source->isLocked());
            $section->setDefaultTopicSort($source->getDefaultTopicSort());
            $section->setSortOrder($source->getSortOrder());
            $section->setRulesHtml($source->getRulesHtml());
            $section->setAccessSecretHash($source->getAccessSecretHash());
        }
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
        if ($parent instanceof ForumSection && $parent->getLocale() !== $section->getLocale()) {
            $parent = $this->sectionRepository->findLocaleSibling($parent, $section->getLocale());
        }

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

        $nodeType = ForumNodeType::tryFrom((string) $request->request->get('node_type', '')) ?? ForumNodeType::fromSectionType($type);
        $section->setNodeType($nodeType);
        $section->setLinkUrl(trim((string) $request->request->get('link_url')));
        $section->setRequiredCapability(trim((string) $request->request->get('required_capability')));
        $section->setLocked($request->request->getBoolean('is_locked'));
        $section->setDefaultTopicSort((string) $request->request->get('default_topic_sort', 'latest'));
        $section->setSlug($this->resolveSectionSlug($section, $request));
        $section->setRulesHtml(trim((string) $request->request->get('rules_html')));

        if ($request->request->getBoolean('clear_access_secret')) {
            $section->setAccessSecretHash(null);
        } else {
            $plain = (string) $request->request->get('access_secret', '');
            if (trim($plain) !== '') {
                $section->setAccessSecretHash($this->accessService->hashSectionSecret($plain));
            }
        }
    }

    private function resolveSectionSlug(ForumSection $section, Request $request): string
    {
        $raw = trim((string) $request->request->get('slug'));
        $source = $raw !== '' ? $raw : trim((string) $request->request->get('title'));
        $slugger = new AsciiSlugger($section->getLocale());
        $slug = strtolower($slugger->slug($source)->toString());
        if ($slug === '') {
            $slug = 'forum';
        }
        $slug = mb_substr($slug, 0, 255);

        $taken = $this->sectionRepository->findOneBySlugAndLocale($slug, $section->getLocale());
        if ($taken instanceof ForumSection && $taken->getId() !== $section->getId()) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.sections.error.slug_taken', ['slug' => $slug, 'locale' => $section->getLocale()]));
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function formValuesFromSection(ForumSection $source, string $targetLocale): array
    {
        $parent = $source->getParent();
        $parentId = null;
        if ($parent instanceof ForumSection) {
            $mapped = $this->sectionRepository->findLocaleSibling($parent, $targetLocale);
            $parentId = $mapped?->getId();
        }

        return [
            'title' => $source->getTitle(),
            'code' => $source->getCode(),
            'slug' => $source->getSlug(),
            'description' => $source->getDescription() ?? '',
            'icon' => $source->getIcon() ?? '',
            'parentId' => $parentId,
            'sortOrder' => $source->getSortOrder(),
            'sectionType' => $source->getSectionType()->value,
            'nodeType' => $source->getNodeType()->value,
            'linkUrl' => $source->getLinkUrl() ?? '',
            'requiredCapability' => $source->getRequiredCapability() ?? '',
            'locked' => $source->isLocked(),
            'defaultTopicSort' => $source->getDefaultTopicSort(),
            'rulesHtml' => $source->getRulesHtml() ?? '',
            'hasAccessSecret' => $source->isPassworded(),
        ];
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
            'nodeType' => ForumNodeType::fromSectionType(
                ForumSectionType::tryFrom($sectionType) ?? ForumSectionType::Division,
            )->value,
            'linkUrl' => '',
            'requiredCapability' => '',
            'locked' => false,
            'defaultTopicSort' => 'latest',
            'rulesHtml' => '',
            'hasAccessSecret' => false,
        ];
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function renderSectionForm(?ForumSection $section, array $formValues, string $locale, ?ForumSection $source = null): Response
    {
        return $this->render('@ForumModule/admin/sections/form.html.twig', [
            'section' => $section,
            'parentOptionsByType' => $this->hierarchyService->buildParentOptionsByType($locale, $section),
            'formValues' => $formValues,
            'locale' => $locale,
            'sourceId' => $source?->getId(),
            'translationTabs' => $section instanceof ForumSection
                ? $this->translationGroupResolver->tabsFor($section)
                : ($source instanceof ForumSection ? $this->translationGroupResolver->tabsFor($source) : []),
            'tabsSourceId' => $section?->getId() ?? $source?->getId(),
        ]);
    }

    private function rebuildPathTree(ForumSection $section): void
    {
        $this->syncSectionParentPath($section);
        foreach ($section->getChildren() as $child) {
            $this->rebuildPathTree($child);
        }
    }

    private function syncSectionParentPath(ForumSection $section): void
    {
        $id = $section->getId();
        if ($id === null) {
            return;
        }

        $parent = $section->getParent();
        if ($parent instanceof ForumSection) {
            $base = $parent->getParentPath() !== ''
                ? rtrim($parent->getParentPath(), '/').'/'
                : '/'.($parent->getId() ?? 0).'/';
            $section->setParentPath($base.$id.'/');

            return;
        }

        $section->setParentPath('/'.$id.'/');
    }

    private function findTranslationSource(mixed $rawId): ?ForumSection
    {
        if ($rawId === null || !ctype_digit((string) $rawId)) {
            return null;
        }

        $source = $this->sectionRepository->find((int) $rawId);

        return $source instanceof ForumSection ? $source : null;
    }

    private function resolveLocale(mixed $raw): string
    {
        return $this->localeProvider->resolve(\is_string($raw) ? $raw : null);
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
