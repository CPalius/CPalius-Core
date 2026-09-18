<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Localization\LocaleProvider;
use App\Core\Pagination\Paginator;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostVote;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicPrefix;
use Modules\Forum\ForumDictionary;
use Modules\Forum\ForumSectionType;
use Modules\Forum\Repository\ForumPostReportRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumPostVoteRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicPrefixRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserRankRepository;
use Modules\Forum\Service\ForumActivityService;
use Modules\Forum\Service\ForumAttachmentService;
use Modules\Forum\Service\ForumBanService;
use Modules\Forum\Service\ForumDraftService;
use Modules\Forum\Service\ForumModerationService;
use Modules\Forum\Service\ForumPollService;
use Modules\Forum\Service\ForumProfileStatsService;
use Modules\Forum\Service\ForumRankService;
use Modules\Forum\Service\ForumReputationService;
use Modules\Forum\Service\ForumSearchService;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Modules\Forum\Service\ForumTopicEngagementService;
use Modules\Forum\Service\ForumTopicService;
use Modules\Forum\Service\ForumUnreadService;
use Modules\Forum\Service\ForumWatchService;
use Modules\Forum\Service\ForumWordFilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Front board / forum / thread hierarchy.
 */
final class ForumFrontController extends AbstractController
{
    public function __construct(
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostReportRepository $postReportRepository,
        private readonly ForumPostVoteRepository $postVoteRepository,
        private readonly ForumTopicPrefixRepository $topicPrefixRepository,
        private readonly ForumUserRankRepository $rankRepository,
        private readonly ForumTopicService $topicService,
        private readonly ForumModerationService $moderationService,
        private readonly ForumSearchService $searchService,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly ForumBanService $banService,
        private readonly ForumRankService $rankService,
        private readonly ForumProfileStatsService $profileStatsService,
        private readonly ForumReputationService $reputationService,
        private readonly ForumActivityService $activityService,
        private readonly ForumTopicEngagementService $engagementService,
        private readonly ForumAttachmentService $attachmentService,
        private readonly ForumDraftService $draftService,
        private readonly ForumPollService $pollService,
        private readonly ForumUnreadService $unreadService,
        private readonly ForumWatchService $watchService,
        private readonly ForumWordFilterService $wordFilterService,
        private readonly LocaleProvider $localeProvider,
        private readonly Paginator $paginator,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forums', name: 'forum_index')]
    #[Route('/forum', name: 'forum_board_legacy')]
    public function index(Request $request): Response
    {
        $this->assertGuestViewAllowed();
        $locale = $request->getLocale();
        $response = $this->render('@Theme/forum/sections.html.twig', [
            'forumHome' => $this->forumHomeContext(),
            'indexTree' => $this->hierarchyService->buildIndexTree($locale),
            'breadcrumbs' => [],
            'currentSection' => null,
            'forumActivity' => $this->activityService->buildPanelState($locale),
            'lastVisitAt' => $this->resolveLastVisit($request),
        ]);

        return $this->withLastVisitCookie($response);
    }

    #[Route('/forums/activity', name: 'forum_activity', methods: ['GET'], priority: 2)]
    #[Route('/forum/activity', name: 'forum_activity_legacy', methods: ['GET'], priority: 2)]
    public function activity(Request $request): Response
    {
        if (!$this->activityService->isEnabled()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $tab = (string) $request->query->get('tab', ForumActivityService::TAB_LATEST_TOPICS);
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit = $request->query->getInt('limit', 0);
        if ($limit <= 0) {
            $limit = $this->activityService->loadMoreStep();
        }

        $chunk = $this->activityService->fetchTab($tab, $offset, $limit, $request->getLocale());

        if ($request->isXmlHttpRequest() || $request->query->getBoolean('partial')) {
            $html = $this->renderView('@Theme/forum/partials/_activity_rows.html.twig', [
                'items' => $chunk['items'],
            ]);

            return $this->json([
                'html' => $html,
                'count' => \count($chunk['items']),
                'hasMore' => $chunk['hasMore'],
                'nextOffset' => $offset + \count($chunk['items']),
            ]);
        }

        return $this->redirectToRoute('forum_index');
    }

    #[Route('/forums/forum/{sectionSlug}', name: 'forum_section', priority: 1, requirements: ['sectionSlug' => '^(?!activity$|ara$|bildirimler$|cevrimici$)[^/]+'])]
    #[Route('/forum/{sectionSlug}', name: 'forum_section_legacy', priority: 1, requirements: ['sectionSlug' => '^(?!activity$|ara$|bildirimler$|cevrimici$)[^/]+'])]
    public function section(Request $request, string $sectionSlug): Response
    {
        $section = $this->findSectionOrFail($sectionSlug, $request->getLocale());
        $request->attributes->set('forum_section', $section);
        $this->assertNodeAccessible($section);

        if ($section->isLinkNode() && $section->getLinkUrl() !== null) {
            return $this->redirect($section->getLinkUrl());
        }

        if ($section->isContainer()) {
            return $this->withLastVisitCookie($this->render('@Theme/forum/sections.html.twig', [
                'forumHome' => $this->forumHomeContext(),
                'indexTree' => $this->hierarchyService->buildSectionTree($section),
                'breadcrumbs' => $this->hierarchyService->getBreadcrumbChain($section),
                'currentSection' => $section,
                'lastVisitAt' => $this->resolveLastVisit($request),
            ]));
        }

        if (!$section->allowsTopics()) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.section.topics_not_allowed'));
        }

        $hidePrivate = (bool) $this->settingsRegistry->get('forum.hide_private_topics', true);
        $viewer = $this->getUser();
        $viewerEntity = $viewer instanceof User ? $viewer : null;
        $filter = (string) $request->query->get('filter', 'all');
        $allowedFilters = ['all', 'solved', 'latest', 'mine', 'popular'];
        if (!\in_array($filter, $allowedFilters, true)) {
            $filter = 'all';
        }
        if ($filter === 'mine' && $viewerEntity === null) {
            $filter = 'all';
        }

        $contentLang = $this->resolveContentLang($request);
        $groupIds = $this->sectionRepository->findGroupSectionIds($section);
        $contentLocale = $contentLang === 'all' ? null : $contentLang;

        $wordFilterTerms = $this->wordFilterService->termsFor($viewerEntity);

        $qb = $this->topicRepository->createSectionTopicsQueryBuilder(
            $section,
            $viewerEntity,
            $hidePrivate,
            $filter,
            $this->isGranted('forum.topic.moderate'),
            null,
            $groupIds,
            $contentLocale,
            $wordFilterTerms,
        );
        $perPage = (int) $this->settingsRegistry->get(
            'forum.threads_per_page',
            $this->settingsRegistry->get('forum.topics_per_page', ForumDictionary::DEFAULT_TOPICS_PER_PAGE),
        );
        $topics = $this->paginator->paginate($qb, $request->query->getInt('page', 1), max(1, $perPage));

        $section->setViewCount($section->getViewCount() + 1);
        $this->entityManager->flush();

        return $this->withLastVisitCookie($this->render('@Theme/forum/topics.html.twig', [
            'section' => $section,
            'breadcrumbs' => $this->hierarchyService->getBreadcrumbChain($section),
            'forumHome' => $this->forumHomeContext(),
            'childSections' => $this->hierarchyService->getSortedChildren($section, ForumSectionType::Subcategory),
            'topics' => $topics,
            'filter' => $filter,
            'contentLang' => $contentLang,
            'contentLangParams' => $this->contentLangQueryParams($contentLang, $request->getLocale()),
            'otherLanguageCounts' => $this->otherLanguageCounts($groupIds, $contentLang),
            'canCreateThread' => $this->canCreateThread($section),
            'wordFilterActive' => $wordFilterTerms !== [] && $filter !== 'mine',
            'hotThreshold' => (int) $this->settingsRegistry->get('forum.hot_topic_threshold', ForumDictionary::HOT_TOPIC_POST_THRESHOLD),
            'postsPerPage' => (int) $this->settingsRegistry->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE),
            'lastVisitAt' => $this->resolveLastVisit($request),
            'unreadTopicIds' => $viewerEntity instanceof User
                ? $this->unreadService->unreadTopicIdMap($viewerEntity, iterator_to_array($topics))
                : [],
        ] + $this->inlineModContext($section)));
    }

    #[Route('/forums/forum/{sectionSlug}/yeni', name: 'forum_new_topic', methods: ['GET', 'POST'])]
    #[Route('/forum/{sectionSlug}/yeni', name: 'forum_new_topic_legacy', methods: ['GET', 'POST'])]
    public function newTopic(Request $request, string $sectionSlug): Response
    {
        $section = $this->findSectionOrFail($sectionSlug, $request->getLocale());
        $request->attributes->set('forum_section', $section);
        $this->assertNodeAccessible($section);

        if (!$this->canCreateThread($section)) {
            throw new AccessDeniedHttpException();
        }

        $this->assertCanCreateThreadInSection($section);

        if ($section->isContainer() || !$section->allowsTopics()) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.section.topics_not_allowed'));
        }

        if ($section->isLocked() && !$this->isGranted('forum.topic.moderate')) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.section.locked'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->assertNotMuted($user);

        $prefixes = array_values(array_filter(
            $this->topicPrefixRepository->findAllOrdered(),
            static fn (ForumTopicPrefix $prefix): bool => $prefix->isAvailableIn($section),
        ));
        $contentLocale = $this->localeProvider->resolve(
            $request->isMethod('POST')
                ? (string) $request->request->get('content_locale', $request->getLocale())
                : $request->getLocale(),
        );

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'forum_new_topic');

            $title = trim((string) $request->request->get('title'));
            $description = trim((string) $request->request->get('description'));
            $body = trim((string) $request->request->get('body'));
            $isPrivate = $request->request->getBoolean('private');
            $prefix = $this->resolvePrefix($request, 'prefix_id', $section);

            if ($title === '' || $body === '') {
                $this->addFlash('error', $this->translator->trans('site.forum.new_topic.title_body_required'));

                return $this->render('@Theme/forum/new_topic.html.twig', $this->newTopicViewData(
                    $section,
                    $prefixes,
                    compact('title', 'description', 'body', 'isPrivate') + [
                        'prefixId' => $prefix?->getId(),
                        'contentLocale' => $contentLocale,
                    ],
                ));
            }

            $topic = $this->topicService->createTopic(
                $section,
                $user,
                $title,
                $description,
                $body,
                $isPrivate,
                $request,
                $prefix,
                $contentLocale,
            );
            $this->finishPublishedTopic($topic, $user, $request, $section);

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic) + ['_locale' => $topic->getLocale()]);
        }

        $draft = $this->draftService->newTopicDraft($user, $section);

        return $this->render('@Theme/forum/new_topic.html.twig', $this->newTopicViewData(
            $section,
            $prefixes,
            [
                'title' => $draft?->getTitle() ?? '',
                'description' => '',
                'body' => $draft?->getBody() ?? '',
                'isPrivate' => false,
                'prefixId' => null,
                'contentLocale' => $contentLocale,
            ],
        ));
    }

    #[Route(
        '/forums/thread/{topicId}-{slug}',
        name: 'forum_topic',
        requirements: ['topicId' => '\d+', 'slug' => '[a-z0-9-]*'],
        defaults: ['slug' => ''],
        priority: 2,
    )]
    #[Route(
        '/forum/konu/{topicId}-{slug}',
        name: 'forum_topic_legacy',
        requirements: ['topicId' => '\d+', 'slug' => '[a-z0-9-]*'],
        defaults: ['slug' => ''],
        priority: 2,
    )]
    public function topic(Request $request, int $topicId, string $slug = ''): Response
    {
        $topic = $this->findTopicOrFail($topicId);
        $this->assertTopicVisible($topic);
        $this->assertNodeAccessible($topic->getSection());
        $displaySection = $this->sectionRepository->findLocaleSibling($topic->getSection(), $request->getLocale())
            ?? $topic->getSection();

        if ($topic->getMovedToTopic() !== null) {
            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic->getMovedToTopic()), 301);
        }

        if ($slug !== ($topic->getSlug() ?? '')) {
            $page = $request->query->getInt('page', 1);
            $params = $this->topicRouteParams($topic);
            if ($page > 1) {
                $params['page'] = $page;
            }

            return $this->redirectToRoute('forum_topic', $params, 301);
        }

        $topic->incrementViewCount();
        $this->entityManager->flush();

        $perPage = (int) $this->settingsRegistry->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE);
        $canModerate = $this->isGranted('forum.topic.moderate');
        $qb = $this->postRepository->createTopicPostsQueryBuilder($topic, $canModerate);
        $posts = $this->paginator->paginate($qb, $request->query->getInt('page', 1), $perPage);

        $canReply = $this->canReplyToTopic($topic);
        $inline = $this->inlineModContext($displaySection);
        $canModerate = $inline['canModerate'];
        $canLock = $inline['canLock'];
        $canSticky = $inline['canSticky'];
        $canMove = $inline['canMove'];

        $postIds = array_map(static fn ($post) => $post->getId(), iterator_to_array($posts));
        $viewer = $this->getUser();
        if ($viewer instanceof User) {
            $this->unreadService->markTopicRead($topic, $viewer);
        }

        $poll = $this->pollService->findForTopic($topic);
        $voteCounts = $this->postVoteRepository->countBothByPostIds($postIds);

        return $this->render('@Theme/forum/posts.html.twig', [
            'topic' => $topic,
            'section' => $displaySection,
            'posts' => $posts,
            'canReply' => $canReply,
            'canModerate' => $canModerate,
            'canLock' => $canLock,
            'canSticky' => $canSticky,
            'canMove' => $canMove,
            'canInlineMod' => $inline['canInlineModPosts'],
            'canDeletePosts' => $inline['canDeletePosts'],
            'canSplit' => $inline['canSplit'],
            'postbitStats' => $this->buildPostbitStats($posts),
            // One grouped query for both tallies now that they share a table,
            // where this used to be two (Manifesto Law 6.1).
            'likeCounts' => array_map(static fn (array $t): int => $t['likes'], $voteCounts),
            'likedPostIds' => $viewer instanceof User ? $this->postVoteRepository->findVotedPostIdsForUser($postIds, $viewer, ForumPostVote::LIKE) : [],
            'dislikeCounts' => array_map(static fn (array $t): int => $t['dislikes'], $voteCounts),
            'dislikedPostIds' => $viewer instanceof User ? $this->postVoteRepository->findVotedPostIdsForUser($postIds, $viewer, ForumPostVote::DISLIKE) : [],
            'topicReaders' => $this->engagementService->readers($topic),
            'topicReactors' => $this->engagementService->reactors($topic),
            'moveTargets' => $inline['moveTargets'],
            'reputationEnabled' => $this->reputationService->isEnabled(),
            'repReasons' => \Modules\Forum\Entity\ForumUserReputation::REASONS,
            'fastReplyEnabled' => (bool) $this->settingsRegistry->get('forum.fast_reply_enabled', true),
            'poll' => $poll,
            'pollVotedIds' => $viewer instanceof User && $poll !== null ? $this->pollService->votedOptionIds($poll, $viewer) : [],
            'watching' => $viewer instanceof User && $this->watchService->isWatching($topic, $viewer),
            'watchEnabled' => $this->watchService->isEnabled(),
            'attachmentsByPost' => $this->attachmentService->groupedByPostIds(array_filter($postIds)),
            'replyDraft' => $viewer instanceof User ? $this->draftService->replyDraft($viewer, $topic) : null,
            'draftsEnabled' => $this->draftService->isEnabled(),
            'pollsEnabled' => $this->pollService->isEnabled(),
            'attachmentsEnabled' => $this->composerUploadEnabled($topic->getSection()),
            'signaturesEnabled' => (bool) $this->settingsRegistry->get('forum.signatures_enabled', true),
        ]);
    }

    /**
     * Per-author postbit stats for the thread page, loaded in bulk (Law 6.1).
     *
     * @return array<int, array{
     *     topicCount: int,
     *     postCount: int,
     *     rank: ?\Modules\Forum\Entity\ForumUserRank,
     *     likesReceived: int,
     *     reputationNet: int,
     *     popularity: int
     * }>
     */
    private function buildPostbitStats(iterable $posts): array
    {
        $authorIds = [];
        foreach ($posts as $post) {
            if ($post->getAuthor() !== null) {
                $authorIds[$post->getAuthor()->getId()] = $post->getAuthor();
            }
        }

        if ($authorIds === []) {
            return [];
        }

        $ids = array_keys($authorIds);
        $topicCounts = $this->topicRepository->countTopicsForUserIds($ids);
        $postCounts = $this->postRepository->countPublicPostsForUserIds($ids);
        $ranks = $this->rankRepository->findAllOrdered();
        $engagement = $this->profileStatsService->batchEngagementForUserIds($ids, $topicCounts, $postCounts);

        $stats = [];
        foreach ($authorIds as $id => $author) {
            $postCount = $postCounts[$id] ?? 0;
            $eng = $engagement[$id] ?? ['likesReceived' => 0, 'reputationNet' => 0, 'popularity' => 0];
            $stats[$id] = [
                'topicCount' => $topicCounts[$id] ?? 0,
                'postCount' => $postCount,
                'rank' => $this->rankService->resolveRankFromPreloaded($author, $postCount, $ranks),
                'likesReceived' => $eng['likesReceived'],
                'reputationNet' => $eng['reputationNet'],
                'popularity' => $eng['popularity'],
            ];
        }

        return $stats;
    }

    #[Route('/forums/thread/{slug}', name: 'forum_thread_by_slug', methods: ['GET'], priority: 1, requirements: ['slug' => '[a-z0-9-]+'])]
    public function topicBySlug(Request $request, string $slug): Response
    {
        $topic = $this->topicRepository->findOneVisibleBySlug($slug);
        if (!$topic instanceof ForumTopic) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.topic_not_found'));
        }

        return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic), 301);
    }

    #[Route('/forums/thread/{topicId}/yanit', name: 'forum_reply', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[Route('/forum/konu/{topicId}/yanit', name: 'forum_reply_legacy', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    public function reply(Request $request, int $topicId): Response
    {
        if (!$this->canCreateThread()) {
            throw new AccessDeniedHttpException();
        }

        $topic = $this->findTopicOrFail($topicId);
        $this->assertTopicVisible($topic);

        if ($topic->isLocked()) {
            throw new BadRequestHttpException($this->translator->trans('site.forum.topic_locked'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->assertNotMuted($user);
        $this->assertCanReplyInSection($topic->getSection());

        $this->assertValidCsrf($request, 'forum_reply');

        $body = trim((string) $request->request->get('body'));
        if ($body === '') {
            $this->addFlash('error', $this->translator->trans('site.forum.reply.body_required'));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic));
        }

        $result = $this->topicService->addReply($topic, $user, $body, $request);
        $post = $result->post;
        $this->attachmentService->attachUploaded($post, $user, $this->uploadedFiles($request));
        $this->draftService->discardReply($user, $topic);
        if ($this->watchService->isEnabled() && (bool) $this->settingsRegistry->get('forum.watch_auto_on_reply', true)) {
            $this->watchService->watch($topic, $user);
        }

        // A folded reply is not a failure, but it is not "your reply was added"
        // either — the member has to be told why the thread did not move.
        $flashKey = match (true) {
            $result->merged => 'forum.antibump.merged_notice',
            $post->isModerated() => 'forum.reply.held',
            default => 'site.forum.reply.added',
        };
        $this->addFlash($result->merged ? 'info' : 'success', $this->translator->trans($flashKey));

        $perPage = (int) $this->settingsRegistry->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE);
        $lastPage = (int) ceil($topic->getPostCount() / max(1, $perPage));

        return $this->redirectToRoute('forum_topic', array_merge($this->topicRouteParams($topic), ['page' => max(1, $lastPage)]));
    }

    #[Route('/forums/mesaj/{postId}/duzenle', name: 'forum_edit_post', methods: ['GET', 'POST'], requirements: ['postId' => '\d+'])]
    #[Route('/forum/mesaj/{postId}/duzenle', name: 'forum_edit_post_legacy', methods: ['GET', 'POST'], requirements: ['postId' => '\d+'])]
    public function editPost(Request $request, int $postId): Response
    {
        $post = $this->findPostOrFail($postId);
        $this->assertTopicVisible($post->getTopic());

        /** @var User|null $user */
        $user = $this->getUser();

        // Moderator = topic.moderate or post.edit.any. Unscoped post.edit is not a moderator grant.
        $isModerator = $this->isGranted('forum.topic.moderate')
            || $this->isGranted('forum.post.edit.any');

        if (!$this->topicService->canEditPost($post, $user, $isModerator)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.edit_post.access_denied'));
        }

        $isFirstPost = $this->postRepository->findFirstByTopic($post->getTopic())?->getId() === $post->getId();

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'forum_edit_post');

            $body = trim((string) $request->request->get('body'));
            $topicTitle = $isFirstPost ? trim((string) $request->request->get('title')) : null;

            if ($body === '' || ($isFirstPost && $topicTitle === '')) {
                $this->addFlash('error', $this->translator->trans('site.forum.edit_post.title_body_required'));

                return $this->render('@Theme/forum/edit_post.html.twig', $this->editPostViewData(
                    $post,
                    $isFirstPost,
                    ['body' => $body, 'title' => $topicTitle ?? $post->getTopic()->getTitle()],
                ));
            }

            $this->topicService->updatePost($post, $user, $body, $isFirstPost ? $topicTitle : null);
            $this->addFlash('success', $this->translator->trans('site.forum.edit_post.updated'));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($post->getTopic()));
        }

        return $this->render('@Theme/forum/edit_post.html.twig', $this->editPostViewData(
            $post,
            $isFirstPost,
            ['body' => $post->getBody(), 'title' => $post->getTopic()->getTitle()],
        ));
    }

    #[Route('/forum/mesaj/{postId}/sil', name: 'forum_delete_post', methods: ['POST'], requirements: ['postId' => '\d+'])]
    public function deletePost(Request $request, int $postId): Response
    {
        $post = $this->findPostOrFail($postId);
        $topic = $post->getTopic();
        $section = $post->getSection();

        /** @var User|null $user */
        $user = $this->getUser();
        $isModerator = $this->isGranted('forum.topic.moderate');
        $isOwner = $user !== null && $post->getAuthor()?->getId() === $user->getId();

        if (!$isModerator && !($isOwner && $this->isGranted('forum.post.delete.own'))) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.delete_post.access_denied'));
        }

        $this->assertValidCsrf($request, 'forum_delete_post');
        $topicId = $topic->getId();
        $this->topicService->deletePost($post);
        $this->addFlash('success', $this->translator->trans('site.forum.delete_post.deleted'));

        $remainingTopic = $this->topicRepository->find($topicId);
        if ($remainingTopic === null) {
            return $this->redirectToRoute('forum_section', ['sectionSlug' => $section->getSlug()]);
        }

        return $this->redirectToRoute('forum_topic', $this->topicRouteParams($remainingTopic));
    }

    #[Route('/forums/thread/{topicId}/moderate', name: 'forum_moderate_topic', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[Route('/forum/konu/{topicId}/moderate', name: 'forum_moderate_topic_legacy', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    public function moderateTopic(Request $request, int $topicId): Response
    {
        $topic = $this->findTopicOrFail($topicId);
        $this->assertValidCsrf($request, 'forum_moderate');

        $action = (string) $request->request->get('action');
        $this->assertThreadModerationCapability($action);

        if ($action === 'move') {
            $targetId = $this->parseOptionalPositiveInt($request, 'target_section_id');
            $target = $targetId !== null ? $this->sectionRepository->find($targetId) : null;

            if (!$target instanceof ForumSection || $target->isContainer() || !$target->allowsTopics()) {
                throw new BadRequestHttpException($this->translator->trans('site.forum.moderate.invalid_target_section'));
            }

            if ($target->getId() === $topic->getSection()->getId()) {
                $this->addFlash('error', $this->translator->trans('site.forum.moderate.already_in_section'));

                return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic));
            }

            $this->topicService->moveTopic($topic, $target, $request->request->getBoolean('keep_redirect'));
            $this->addFlash('success', $this->translator->trans('site.forum.moderate.topic_moved', ['title' => $target->getTitle()]));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic));
        }

        match ($action) {
            'lock' => $topic->setLocked(true),
            'unlock' => $topic->setLocked(false),
            'sticky' => $topic->setSticky(true),
            'unsticky' => $topic->setSticky(false),
            'announce' => $topic->setLocked(true)->setSticky(true),
            'clear' => $topic->setLocked(false)->setSticky(false)->setMode(ForumTopic::MODE_NORMAL),
            'prefix' => $topic->setPrefix($this->resolvePrefix($request)),
            'delete' => $this->topicService->deleteTopic($topic),
            'restore' => $this->topicService->restoreTopic($topic),
            default => throw new BadRequestHttpException($this->translator->trans('site.forum.moderate.invalid_action')),
        };

        if ($action !== 'delete') {
            $topic->touch();
            $this->entityManager->flush();
        }

        if ($action === 'delete') {
            $this->addFlash('success', $this->translator->trans('site.forum.moderate.topic_deleted'));

            return $this->redirectToRoute('forum_section', ['sectionSlug' => $topic->getSection()->getSlug()]);
        }

        $this->addFlash('success', $this->translator->trans('site.forum.moderate.topic_updated'));

        return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic));
    }

    #[Route('/forum/mesaj/{postId}/begen', name: 'forum_like_post', methods: ['POST'], requirements: ['postId' => '\d+'])]
    #[IsGranted('forum.post.like')]
    public function likePost(Request $request, int $postId): Response
    {
        $post = $this->findPostOrFail($postId);
        $this->assertTopicVisible($post->getTopic());
        $this->assertValidCsrf($request, 'forum_like_post');

        /** @var User $user */
        $user = $this->getUser();
        $this->assertNotMuted($user);
        $this->assertCanReactToPost($post, $user);

        $liked = $this->topicService->toggleLike($post, $user);
        $count = $this->postVoteRepository->countByPost($post, ForumPostVote::LIKE);
        $siblingCount = $this->postVoteRepository->countByPost($post, ForumPostVote::DISLIKE);

        if ($this->wantsJson($request)) {
            return $this->json([
                'liked' => $liked,
                'count' => $count,
                'siblingCount' => $siblingCount,
            ]);
        }

        return $this->redirectToRoute('forum_topic', array_merge($this->topicRouteParams($post->getTopic()), [
            'page' => $request->query->getInt('page', 1),
            '_fragment' => 'post'.$post->getId(),
        ]));
    }

    #[Route('/forum/mesaj/{postId}/begenme', name: 'forum_dislike_post', methods: ['POST'], requirements: ['postId' => '\d+'])]
    #[IsGranted('forum.post.like')]
    public function dislikePost(Request $request, int $postId): Response
    {
        $post = $this->findPostOrFail($postId);
        $this->assertTopicVisible($post->getTopic());
        $this->assertValidCsrf($request, 'forum_dislike_post');

        /** @var User $user */
        $user = $this->getUser();
        $this->assertNotMuted($user);
        $this->assertCanReactToPost($post, $user);

        $disliked = $this->topicService->toggleDislike($post, $user);
        $count = $this->postVoteRepository->countByPost($post, ForumPostVote::DISLIKE);
        $siblingCount = $this->postVoteRepository->countByPost($post, ForumPostVote::LIKE);

        if ($this->wantsJson($request)) {
            return $this->json([
                'disliked' => $disliked,
                'count' => $count,
                'siblingCount' => $siblingCount,
            ]);
        }

        return $this->redirectToRoute('forum_topic', array_merge($this->topicRouteParams($post->getTopic()), [
            'page' => $request->query->getInt('page', 1),
            '_fragment' => 'post'.$post->getId(),
        ]));
    }

    #[Route('/forum/mesaj/{postId}/rapor', name: 'forum_report_post', methods: ['POST'], requirements: ['postId' => '\d+'])]
    #[IsGranted('forum.post.report')]
    public function reportPost(Request $request, int $postId): Response
    {
        $post = $this->findPostOrFail($postId);
        $this->assertTopicVisible($post->getTopic());
        $this->assertValidCsrf($request, 'forum_report_post');

        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', $this->translator->trans('site.forum.report.reason_required'));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($post->getTopic()));
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($this->postReportRepository->hasOpenReportFrom($post, $user->getId())) {
            $this->addFlash('error', $this->translator->trans('site.forum.report.already_reported'));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($post->getTopic()));
        }

        $this->moderationService->reportPost($post, $reason, $user);
        $this->addFlash('success', $this->translator->trans('site.forum.report.submitted'));

        return $this->redirectToRoute('forum_topic', $this->topicRouteParams($post->getTopic()));
    }

    #[Route('/forums/cevrimici', name: 'forum_online', methods: ['GET'], priority: 4)]
    #[Route('/forum/cevrimici', name: 'forum_online_legacy', methods: ['GET'], priority: 4)]
    public function online(): Response
    {
        $this->assertGuestViewAllowed();

        return $this->render('@Theme/forum/online.html.twig', [
            'forumHome' => $this->forumHomeContext(),
        ]);
    }

    #[Route('/forums/ara', name: 'forum_search', methods: ['GET'], priority: 3)]
    #[Route('/forum/ara', name: 'forum_search_legacy', methods: ['GET'], priority: 3)]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q'));
        $scope = $request->query->get('scope', 'all');
        $sectionFilter = (int) $request->query->get('section', 0);

        if (!\in_array($scope, ['all', 'topics', 'posts'], true)) {
            $scope = 'all';
        }

        $locale = $request->getLocale();
        $searchableSections = $this->hierarchyService->getTopicBoards($locale);

        $topicResults = [];
        $postResults = [];
        $totalResults = 0;

        if ($query !== '' && mb_strlen($query) >= 2) {
            $results = $this->searchService->search(
                $query,
                $scope,
                $sectionFilter > 0 ? $sectionFilter : null,
                20,
                $locale,
                trim((string) $request->query->get('author')) ?: null,
                $this->parseSearchDate($request->query->get('from')),
                $this->parseSearchDate($request->query->get('to'), true),
            );
            $topicResults = $results['topics'];
            $postResults = $results['posts'];
            $totalResults = $results['totalTopics'] + $results['totalPosts'];
        }

        return $this->render('@Theme/forum/search.html.twig', [
            'query' => $query,
            'scope' => $scope,
            'sectionFilter' => $sectionFilter > 0 ? $sectionFilter : null,
            'sections' => $searchableSections,
            'topicResults' => $topicResults,
            'postResults' => $postResults,
            'totalResults' => $totalResults,
            'author' => trim((string) $request->query->get('author')),
            'dateFrom' => (string) $request->query->get('from'),
            'dateTo' => (string) $request->query->get('to'),
        ]);
    }

    private function findSectionOrFail(string $slug, string $locale): ForumSection
    {
        $section = $this->sectionRepository->findOneBySlugAndLocale($slug, $locale);
        if (!$section instanceof ForumSection) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.section_not_found'));
        }

        return $section;
    }

    private function resolveContentLang(Request $request): string
    {
        $raw = trim((string) $request->query->get('content_lang', ''));
        if ($raw === 'all') {
            return 'all';
        }

        if ($raw !== '' && $this->localeProvider->isSupported($raw)) {
            return $raw;
        }

        return $request->getLocale();
    }

    /**
     * @return array<string, string>
     */
    private function contentLangQueryParams(string $contentLang, string $uiLocale): array
    {
        if ($contentLang === 'all') {
            return ['content_lang' => 'all'];
        }

        if ($contentLang !== $uiLocale) {
            return ['content_lang' => $contentLang];
        }

        return [];
    }

    /**
     * @param list<int> $groupIds
     *
     * @return list<array{code: string, nativeName: string, count: int}>
     */
    private function otherLanguageCounts(array $groupIds, string $contentLang): array
    {
        $counts = $this->topicRepository->countVisibleBySectionIdsGroupedByLocale($groupIds);
        $other = [];
        foreach ($this->localeProvider->getLocales() as $locale) {
            $n = $counts[$locale->code] ?? 0;
            if ($n <= 0 || $locale->code === $contentLang) {
                continue;
            }
            $other[] = [
                'code' => $locale->code,
                'nativeName' => $locale->nativeName,
                'count' => $n,
            ];
        }

        return $other;
    }

    /**
     * @param list<ForumTopicPrefix> $prefixes
     * @param array<string, mixed>   $formValues
     *
     * @return array<string, mixed>
     */
    private function newTopicViewData(ForumSection $section, array $prefixes, array $formValues): array
    {
        return [
            'section' => $section,
            'prefixes' => $prefixes,
            'formValues' => $formValues,
            'contentLocales' => $this->localeProvider->getLocales(),
            'draftsEnabled' => $this->draftService->isEnabled(),
            'pollsEnabled' => $this->pollService->isEnabled() && $this->isGranted('forum.node.poll', $section),
            'attachmentsEnabled' => $this->composerUploadEnabled($section),
        ];
    }

    /**
     * @param array{body: string, title: string} $formValues
     *
     * @return array<string, mixed>
     */
    private function editPostViewData(ForumPost $post, bool $isFirstPost, array $formValues): array
    {
        $section = $post->getSection();
        $windowMinutes = $this->topicService->editWindowMinutes($post);
        $deadline = $post->getCreatedAt()->getTimestamp() + ($windowMinutes * 60);

        return [
            'post' => $post,
            'topic' => $post->getTopic(),
            'section' => $section,
            'isFirstPost' => $isFirstPost,
            'formValues' => $formValues,
            'attachmentsEnabled' => $this->composerUploadEnabled($section),
            // A window nobody can see is a window that gets discovered by losing
            // work to it. Moderators edit outside it, so the countdown is only
            // meaningful for the author.
            'editWindowMinutes' => $windowMinutes,
            'editMinutesLeft' => max(0, (int) ceil(($deadline - time()) / 60)),
        ];
    }

    private function composerUploadEnabled(ForumSection $section): bool
    {
        return $this->attachmentService->isEnabled() && $this->isGranted('forum.node.upload', $section);
    }

    private function finishPublishedTopic(ForumTopic $topic, User $user, Request $request, ForumSection $section): void
    {
        $first = $this->postRepository->findFirstByTopic($topic);
        if ($first !== null) {
            $this->attachmentService->attachUploaded($first, $user, $this->uploadedFiles($request));
        }

        $question = trim((string) $request->request->get('poll_question'));
        if ($question !== '') {
            $options = preg_split('/\r\n|\r|\n/', (string) $request->request->get('poll_options', '')) ?: [];
            $closesRaw = trim((string) $request->request->get('poll_closes_at'));
            $closesAt = $closesRaw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $closesRaw) ?: null : null;
            $this->pollService->create(
                $topic,
                $user,
                $question,
                is_array($options) ? $options : [],
                max(1, $request->request->getInt('poll_max_choices', 1)),
                $request->request->getBoolean('poll_hide_until_close'),
                $closesAt instanceof \DateTimeImmutable ? $closesAt : null,
            );
        }

        $this->draftService->discardNewTopic($user, $section);
        if ($this->watchService->isEnabled()) {
            $this->watchService->watch($topic, $user);
        }
    }

    /** @return list<\Symfony\Component\HttpFoundation\File\UploadedFile> */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->files->get('attachments', []);
        if ($files instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return [$files];
        }
        if (!\is_array($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $out[] = $file;
            }
        }

        return $out;
    }

    private function parseSearchDate(mixed $raw, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || trim($raw) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($raw));
        if ($date === false) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
    }

    private function findTopicOrFail(int $topicId): ForumTopic
    {
        $topic = $this->topicRepository->find($topicId);
        if (!$topic instanceof ForumTopic) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.topic_not_found'));
        }

        return $topic;
    }

    private function findPostOrFail(int $postId): ForumPost
    {
        $post = $this->postRepository->find($postId);
        if (!$post instanceof ForumPost) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.post_not_found'));
        }

        return $post;
    }

    private function assertTopicVisible(ForumTopic $topic): void
    {
        if ($topic->isDeleted() && !$this->isGranted('forum.topic.moderate')) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.topic_not_found'));
        }

        if ($topic->isModerated()) {
            /** @var User|null $user */
            $user = $this->getUser();
            $isAuthor = $user !== null && $topic->getFirstPoster()?->getId() === $user->getId();
            if (!$isAuthor && !$this->isGranted('forum.topic.moderate')) {
                throw new NotFoundHttpException($this->translator->trans('site.forum.topic_not_found'));
            }
        }

        if (!$topic->isPrivate()) {
            return;
        }

        /** @var User|null $user */
        $user = $this->getUser();

        if ($this->isGranted('forum.topic.moderate')) {
            return;
        }

        if ($user !== null && $topic->getFirstPoster()?->getId() === $user->getId()) {
            return;
        }

        throw new AccessDeniedHttpException($this->translator->trans('site.forum.private_topic_denied'));
    }

    private function assertNodeAccessible(ForumSection $section): void
    {
        $this->assertGuestViewAllowed();

        if (!$this->isGranted('forum.node.view', $section)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.private_topic_denied'));
        }

        $cursor = $section;
        while ($cursor instanceof ForumSection) {
            $capability = $cursor->getRequiredCapability();
            if ($capability !== null && $capability !== '' && !$this->isGranted($capability)) {
                throw new AccessDeniedHttpException($this->translator->trans('site.forum.private_topic_denied'));
            }
            $cursor = $cursor->getParent();
        }
    }

    private function assertCanCreateThreadInSection(ForumSection $section): void
    {
        if (!$this->isGranted('forum.node.thread_create', $section)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.section.locked'));
        }
    }

    private function assertCanReplyInSection(ForumSection $section): void
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->isGranted('forum.node.reply', $section)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.topic_locked'));
        }
    }

    private function assertGuestViewAllowed(): void
    {
        if ($this->getUser() instanceof User) {
            return;
        }

        if (!(bool) $this->settingsRegistry->get('forum.allow_guest_view', true)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.guest_view_denied'));
        }
    }

    /**
     * @return array{
     *     canModerate: bool,
     *     canLock: bool,
     *     canSticky: bool,
     *     canMove: bool,
     *     canInlineMod: bool,
     *     canInlineModPosts: bool,
     *     canDeletePosts: bool,
     *     canSplit: bool,
     *     moveTargets: list<ForumSection>
     * }
     */
    private function inlineModContext(ForumSection $section): array
    {
        $canModerate = $this->isGranted('forum.topic.moderate');
        $canLock = $canModerate || $this->isGranted('forum.thread.lock');
        $canSticky = $canModerate || $this->isGranted('forum.thread.sticky');
        $canMove = $canModerate || $this->isGranted('forum.thread.move');
        $canDeletePosts = $canModerate || $this->isGranted('forum.post.delete.any');
        $canSplit = $canModerate || $this->isGranted('forum.thread.split');

        return [
            'canModerate' => $canModerate,
            'canLock' => $canLock,
            'canSticky' => $canSticky,
            'canMove' => $canMove,
            'canInlineMod' => $canModerate || $canLock || $canSticky || $canMove,
            'canInlineModPosts' => $canModerate || $canDeletePosts || $canSplit,
            'canDeletePosts' => $canDeletePosts,
            'canSplit' => $canSplit,
            'moveTargets' => ($canMove || $canModerate)
                ? $this->hierarchyService->getTopicBoards($section->getLocale(), $section)
                : [],
        ];
    }

    private function canCreateThread(?ForumSection $section = null): bool
    {
        if (!$this->isGranted('forum.thread.create') && !$this->isGranted('forum.topic.create')) {
            return false;
        }

        if ($section === null) {
            return true;
        }

        if ($section->isLocked() && !$this->isGranted('forum.topic.moderate')) {
            return false;
        }

        return $this->isGranted('forum.node.thread_create', $section);
    }

    private function canReplyToTopic(ForumTopic $topic): bool
    {
        if (!(bool) $this->settingsRegistry->get('forum.fast_reply_enabled', true)) {
            return false;
        }

        if ($topic->isLocked() || $topic->getSection()->isLocked()) {
            return false;
        }

        if (!$this->canCreateThread($topic->getSection())) {
            return false;
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->isGranted('forum.node.reply', $topic->getSection());
    }

    private function assertThreadModerationCapability(string $action): void
    {
        if ($this->isGranted('forum.topic.moderate')) {
            return;
        }

        $map = [
            'lock' => 'forum.thread.lock',
            'unlock' => 'forum.thread.lock',
            'sticky' => 'forum.thread.sticky',
            'unsticky' => 'forum.thread.sticky',
            'move' => 'forum.thread.move',
            'announce' => 'forum.thread.sticky',
            'clear' => 'forum.topic.moderate',
            'prefix' => 'forum.topic.moderate',
            'delete' => 'forum.topic.moderate',
            'restore' => 'forum.topic.moderate',
        ];

        $required = $map[$action] ?? 'forum.topic.moderate';
        if (!$this->isGranted($required)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.moderate.invalid_action'));
        }
    }

    private function resolveLastVisit(Request $request): ?\DateTimeImmutable
    {
        $raw = $request->cookies->get('forum_last_visit');
        if ($raw === null || !ctype_digit($raw)) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $raw);
    }

    private function withLastVisitCookie(Response $response): Response
    {
        $response->headers->setCookie(new Cookie(
            'forum_last_visit',
            (string) time(),
            time() + 60 * 60 * 24 * 30,
            '/',
            null,
            false,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('site.forum.csrf_invalid'));
        }
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json');
    }

    /** @return array{topicId: int, slug: string} */
    private function topicRouteParams(ForumTopic $topic): array
    {
        return ['topicId' => $topic->getId(), 'slug' => $topic->getSlug() ?? ''];
    }

    /**
     * Block MUTED users from write actions. BAN is already handled by ForumBanGuardListener.
     */
    private function assertNotMuted(User $user): void
    {
        $mute = $this->banService->activeMuteFor($user);
        if ($mute !== null) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.muted', ['reason' => $mute->getReason()]));
        }
    }

    private function assertCanReactToPost(ForumPost $post, User $user): void
    {
        $author = $post->getAuthor();
        if ($author !== null && $author->getId() === $user->getId()) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.react.own_post_denied'));
        }
    }

    private function resolvePrefix(Request $request, string $field = 'prefix_id', ?ForumSection $section = null): ?ForumTopicPrefix
    {
        $id = $this->parseOptionalPositiveInt($request, $field);
        if ($id === null) {
            return null;
        }

        $prefix = $this->topicPrefixRepository->find($id);
        if (!$prefix instanceof ForumTopicPrefix) {
            return null;
        }

        if ($section !== null && !$prefix->isAvailableIn($section)) {
            return null;
        }

        return $prefix;
    }

    /**
     * Empty select values become null without triggering FILTER_VALIDATE_INT warnings.
     */
    private function parseOptionalPositiveInt(Request $request, string $field): ?int
    {
        $raw = trim((string) $request->request->get($field, ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /** @return array{label: string, title: string, description: string, metaDescription: string, metaKeywords: string} */
    private function forumHomeContext(): array
    {
        return [
            'label' => (string) $this->settingsRegistry->get('forum.home_label', $this->translator->trans('forum.settings.default_home_label')),
            'title' => (string) $this->settingsRegistry->get('forum.home_title', 'Forum'),
            'description' => (string) $this->settingsRegistry->get('forum.home_description', $this->translator->trans('forum.settings.default_home_description')),
            'metaDescription' => (string) $this->settingsRegistry->get('forum.home_meta_description', $this->translator->trans('forum.settings.default_home_meta_description')),
            'metaKeywords' => (string) $this->settingsRegistry->get('forum.home_meta_keywords', ''),
        ];
    }
}
