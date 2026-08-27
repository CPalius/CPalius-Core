<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Pagination\Paginator;
use App\Core\Settings\SettingsRegistry;
use App\Entity\ForumPost;
use App\Entity\ForumSection;
use App\Entity\ForumTopic;
use App\Entity\ForumTopicPrefix;
use App\Entity\User;
use App\Repository\ForumPostDislikeRepository;
use App\Repository\ForumPostLikeRepository;
use App\Repository\ForumPostRepository;
use App\Repository\ForumPostReportRepository;
use App\Repository\ForumSectionRepository;
use App\Repository\ForumTopicPrefixRepository;
use App\Repository\ForumTopicRepository;
use App\Repository\ForumUserRankRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\ForumDictionary;
use Modules\Forum\ForumSectionType;
use Modules\Forum\Service\ForumActivityService;
use Modules\Forum\Service\ForumBanService;
use Modules\Forum\Service\ForumModerationService;
use Modules\Forum\Service\ForumRankService;
use Modules\Forum\Service\ForumSearchService;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Modules\Forum\Service\ForumTopicService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forum ön yüz — Cotonti forums.sections / forums.topics / forums.posts /
 * forums.newtopic / forums.editpost modlarının Symfony karşılığı.
 */
final class ForumFrontController extends AbstractController
{
    public function __construct(
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumPostReportRepository $postReportRepository,
        private readonly ForumPostLikeRepository $postLikeRepository,
        private readonly ForumPostDislikeRepository $postDislikeRepository,
        private readonly ForumTopicPrefixRepository $topicPrefixRepository,
        private readonly ForumUserRankRepository $rankRepository,
        private readonly ForumTopicService $topicService,
        private readonly ForumModerationService $moderationService,
        private readonly ForumSearchService $searchService,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly ForumBanService $banService,
        private readonly ForumRankService $rankService,
        private readonly ForumActivityService $activityService,
        private readonly Paginator $paginator,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forum', name: 'forum_index')]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();

        return $this->render('@CpaliusWebsiteTheme/forum/sections.html.twig', [
            'forumHome' => $this->forumHomeContext(),
            'indexTree' => $this->hierarchyService->buildIndexTree($locale),
            'breadcrumbs' => [],
            'currentSection' => null,
            'forumStats' => $this->hierarchyService->aggregateStats($locale),
            'forumActivity' => $this->activityService->buildPanelState(),
        ]);
    }

    #[Route('/forum/activity', name: 'forum_activity', methods: ['GET'], priority: 2)]
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

        $chunk = $this->activityService->fetchTab($tab, $offset, $limit);

        if ($request->isXmlHttpRequest() || $request->query->getBoolean('partial')) {
            $html = $this->renderView('@CpaliusWebsiteTheme/forum/partials/_activity_rows.html.twig', [
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

    #[Route('/forum/{sectionSlug}', name: 'forum_section', priority: 1)]
    public function section(Request $request, string $sectionSlug): Response
    {
        $section = $this->findSectionOrFail($sectionSlug, $request->getLocale());

        if ($section->isContainer()) {
            return $this->render('@CpaliusWebsiteTheme/forum/sections.html.twig', [
                'forumHome' => $this->forumHomeContext(),
                'indexTree' => $this->hierarchyService->buildSectionTree($section),
                'breadcrumbs' => $this->hierarchyService->getBreadcrumbChain($section),
                'currentSection' => $section,
                'forumStats' => $this->hierarchyService->aggregateStats($request->getLocale()),
            ]);
        }

        if (!$section->allowsTopics()) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.section.topics_not_allowed'));
        }

        $hidePrivate = (bool) $this->settingsRegistry->get('forum.hide_private_topics', true);
        $viewer = $this->getUser();
        $viewerEntity = $viewer instanceof User ? $viewer : null;

        $qb = $this->topicRepository->createSectionTopicsQueryBuilder($section, $viewerEntity, $hidePrivate);
        $perPage = (int) $this->settingsRegistry->get('forum.topics_per_page', ForumDictionary::DEFAULT_TOPICS_PER_PAGE);
        $topics = $this->paginator->paginate($qb, $request->query->getInt('page', 1), $perPage);

        $section->setViewCount($section->getViewCount() + 1);
        $this->entityManager->flush();

        return $this->render('@CpaliusWebsiteTheme/forum/topics.html.twig', [
            'section' => $section,
            'breadcrumbs' => $this->hierarchyService->getBreadcrumbChain($section),
            'forumHome' => $this->forumHomeContext(),
            'childSections' => $this->hierarchyService->getSortedChildren($section, ForumSectionType::Subcategory),
            'topics' => $topics,
            'hotThreshold' => (int) $this->settingsRegistry->get('forum.hot_topic_threshold', ForumDictionary::HOT_TOPIC_POST_THRESHOLD),
            'postsPerPage' => (int) $this->settingsRegistry->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE),
        ]);
    }

    #[Route('/forum/{sectionSlug}/yeni', name: 'forum_new_topic', methods: ['GET', 'POST'])]
    #[IsGranted('forum.topic.create')]
    public function newTopic(Request $request, string $sectionSlug): Response
    {
        $section = $this->findSectionOrFail($sectionSlug, $request->getLocale());

        if ($section->isContainer() || !$section->allowsTopics()) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.section.topics_not_allowed'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->assertNotMuted($user);

        $prefixes = $this->topicPrefixRepository->findAllOrdered();

        if ($request->isMethod('POST')) {
            $this->assertValidCsrf($request, 'forum_new_topic');

            $title = trim((string) $request->request->get('title'));
            $description = trim((string) $request->request->get('description'));
            $body = trim((string) $request->request->get('body'));
            $isPrivate = $request->request->getBoolean('private');
            $prefix = $this->resolvePrefix($request);

            if ($title === '' || $body === '') {
                $this->addFlash('error', $this->translator->trans('site.forum.new_topic.title_body_required'));

                return $this->render('@CpaliusWebsiteTheme/forum/new_topic.html.twig', [
                    'section' => $section,
                    'prefixes' => $prefixes,
                    'formValues' => compact('title', 'description', 'body', 'isPrivate') + ['prefixId' => $prefix?->getId()],
                ]);
            }

            $topic = $this->topicService->createTopic($section, $user, $title, $description, $body, $isPrivate, $request, $prefix);

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic));
        }

        return $this->render('@CpaliusWebsiteTheme/forum/new_topic.html.twig', [
            'section' => $section,
            'prefixes' => $prefixes,
            'formValues' => ['title' => '', 'description' => '', 'body' => '', 'isPrivate' => false, 'prefixId' => null],
        ]);
    }

    #[Route(
        '/forum/konu/{topicId}-{slug}',
        name: 'forum_topic',
        requirements: ['topicId' => '\d+', 'slug' => '[a-z0-9-]*'],
        defaults: ['slug' => ''],
        priority: 2,
    )]
    public function topic(Request $request, int $topicId, string $slug = ''): Response
    {
        $topic = $this->findTopicOrFail($topicId);
        $this->assertTopicVisible($topic);

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
        $qb = $this->postRepository->createTopicPostsQueryBuilder($topic);
        $posts = $this->paginator->paginate($qb, $request->query->getInt('page', 1), $perPage);

        $canReply = $this->isGranted('forum.topic.create') && !$topic->isLocked();
        $canModerate = $this->isGranted('forum.topic.moderate');

        $postIds = array_map(static fn ($post) => $post->getId(), iterator_to_array($posts));
        $viewer = $this->getUser();

        return $this->render('@CpaliusWebsiteTheme/forum/posts.html.twig', [
            'topic' => $topic,
            'section' => $topic->getSection(),
            'posts' => $posts,
            'canReply' => $canReply,
            'canModerate' => $canModerate,
            'postbitStats' => $this->buildPostbitStats($posts),
            'likeCounts' => $this->postLikeRepository->countByPostIds($postIds),
            'likedPostIds' => $viewer instanceof User ? $this->postLikeRepository->findLikedPostIdsForUser($postIds, $viewer) : [],
            'dislikeCounts' => $this->postDislikeRepository->countByPostIds($postIds),
            'dislikedPostIds' => $viewer instanceof User ? $this->postDislikeRepository->findDislikedPostIdsForUser($postIds, $viewer) : [],
            'moveTargets' => $canModerate
                ? $this->hierarchyService->getTopicBoards($topic->getSection()->getLocale(), $topic->getSection())
                : [],
        ]);
    }

    /**
     * Thread sayfasındaki her benzersiz yazar için { userId: {topicCount,
     * postCount, rank} } — hepsi TOPLU sorgularla (Law 6.1 N+1 muhafızı).
     *
     * @return array<int, array{topicCount: int, postCount: int, rank: ?\App\Entity\ForumUserRank}>
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

        $stats = [];
        foreach ($authorIds as $id => $author) {
            $postCount = $postCounts[$id] ?? 0;
            $stats[$id] = [
                'topicCount' => $topicCounts[$id] ?? 0,
                'postCount' => $postCount,
                'rank' => $this->rankService->resolveRankFromPreloaded($author, $postCount, $ranks),
            ];
        }

        return $stats;
    }

    #[Route('/forum/konu/{topicId}/yanit', name: 'forum_reply', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[IsGranted('forum.topic.create')]
    public function reply(Request $request, int $topicId): Response
    {
        $topic = $this->findTopicOrFail($topicId);
        $this->assertTopicVisible($topic);

        if ($topic->isLocked()) {
            throw new BadRequestHttpException($this->translator->trans('site.forum.topic_locked'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->assertNotMuted($user);

        $this->assertValidCsrf($request, 'forum_reply');

        $body = trim((string) $request->request->get('body'));
        if ($body === '') {
            $this->addFlash('error', $this->translator->trans('site.forum.reply.body_required'));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($topic));
        }

        $this->topicService->addReply($topic, $user, $body, $request);
        $this->addFlash('success', $this->translator->trans('site.forum.reply.added'));

        $perPage = (int) $this->settingsRegistry->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE);
        $lastPage = (int) ceil($topic->getPostCount() / max(1, $perPage));

        return $this->redirectToRoute('forum_topic', array_merge($this->topicRouteParams($topic), ['page' => max(1, $lastPage)]));
    }

    #[Route('/forum/mesaj/{postId}/duzenle', name: 'forum_edit_post', methods: ['GET', 'POST'], requirements: ['postId' => '\d+'])]
    public function editPost(Request $request, int $postId): Response
    {
        $post = $this->findPostOrFail($postId);
        $this->assertTopicVisible($post->getTopic());

        /** @var User|null $user */
        $user = $this->getUser();
        $isModerator = $this->isGranted('forum.topic.moderate');

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

                return $this->render('@CpaliusWebsiteTheme/forum/edit_post.html.twig', [
                    'post' => $post,
                    'topic' => $post->getTopic(),
                    'section' => $post->getSection(),
                    'isFirstPost' => $isFirstPost,
                    'formValues' => ['body' => $body, 'title' => $topicTitle ?? $post->getTopic()->getTitle()],
                ]);
            }

            $this->topicService->updatePost($post, $user, $body, $isFirstPost ? $topicTitle : null);
            $this->addFlash('success', $this->translator->trans('site.forum.edit_post.updated'));

            return $this->redirectToRoute('forum_topic', $this->topicRouteParams($post->getTopic()));
        }

        return $this->render('@CpaliusWebsiteTheme/forum/edit_post.html.twig', [
            'post' => $post,
            'topic' => $post->getTopic(),
            'section' => $post->getSection(),
            'isFirstPost' => $isFirstPost,
            'formValues' => ['body' => $post->getBody(), 'title' => $post->getTopic()->getTitle()],
        ]);
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

    #[Route('/forum/konu/{topicId}/moderate', name: 'forum_moderate_topic', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[IsGranted('forum.topic.moderate')]
    public function moderateTopic(Request $request, int $topicId): Response
    {
        $topic = $this->findTopicOrFail($topicId);
        $this->assertValidCsrf($request, 'forum_moderate');

        $action = (string) $request->request->get('action');

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
            'lock' => $topic->setState(ForumTopic::STATE_LOCKED),
            'unlock' => $topic->setState(ForumTopic::STATE_OPEN),
            'sticky' => $topic->setSticky(true),
            'unsticky' => $topic->setSticky(false),
            'announce' => $topic->setState(ForumTopic::STATE_LOCKED)->setSticky(true),
            'clear' => $topic->setState(ForumTopic::STATE_OPEN)->setSticky(false)->setMode(ForumTopic::MODE_NORMAL),
            'prefix' => $topic->setPrefix($this->resolvePrefix($request)),
            'delete' => $this->topicService->deleteTopic($topic),
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

        $liked = $this->topicService->toggleLike($post, $user);
        $count = $this->postLikeRepository->countByPost($post);
        $siblingCount = $this->postDislikeRepository->countByPost($post);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'liked' => $liked,
                'count' => $count,
                'siblingCount' => $siblingCount,
            ]);
        }

        return $this->redirectToRoute('forum_topic', array_merge($this->topicRouteParams($post->getTopic()), [
            'page' => $request->query->getInt('page', 1),
            '_fragment' => 'post' . $post->getId(),
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

        $disliked = $this->topicService->toggleDislike($post, $user);
        $count = $this->postDislikeRepository->countByPost($post);
        $siblingCount = $this->postLikeRepository->countByPost($post);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'disliked' => $disliked,
                'count' => $count,
                'siblingCount' => $siblingCount,
            ]);
        }

        return $this->redirectToRoute('forum_topic', array_merge($this->topicRouteParams($post->getTopic()), [
            'page' => $request->query->getInt('page', 1),
            '_fragment' => 'post' . $post->getId(),
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

    #[Route('/forum/ara', name: 'forum_search', methods: ['GET'], priority: 3)]
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
            );
            $topicResults = $results['topics'];
            $postResults = $results['posts'];
            $totalResults = $results['totalTopics'] + $results['totalPosts'];
        }

        return $this->render('@CpaliusWebsiteTheme/forum/search.html.twig', [
            'query' => $query,
            'scope' => $scope,
            'sectionFilter' => $sectionFilter > 0 ? $sectionFilter : null,
            'sections' => $searchableSections,
            'topicResults' => $topicResults,
            'postResults' => $postResults,
            'totalResults' => $totalResults,
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

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('site.forum.csrf_invalid'));
        }
    }

    /** @return array{topicId: int, slug: string} */
    private function topicRouteParams(ForumTopic $topic): array
    {
        return ['topicId' => $topic->getId(), 'slug' => $topic->getSlug() ?? ''];
    }

    /**
     * Forum-özel MUTE (susturma) uygulanan kullanıcının yeni konu/yanıt/
     * beğeni gibi yazma eylemlerini engeller. BAN zaten ForumBanGuardListener
     * tarafından tüm ön yüz rotalarında merkezi olarak engellenir.
     */
    private function assertNotMuted(User $user): void
    {
        $mute = $this->banService->activeMuteFor($user);
        if ($mute !== null) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.muted', ['reason' => $mute->getReason()]));
        }
    }

    private function resolvePrefix(Request $request, string $field = 'prefix_id'): ?ForumTopicPrefix
    {
        $id = $this->parseOptionalPositiveInt($request, $field);
        if ($id === null) {
            return null;
        }

        return $this->topicPrefixRepository->find($id);
    }

    /**
     * $request->request->getInt() yerine kullanılır: boş string ("— Yok —"
     * gibi bir <select> seçeneğinden gelen "") ile çağrıldığında PHP'nin
     * filter_var(FILTER_VALIDATE_INT) uyarısını (FILTER_NULL_ON_FAILURE
     * bayrağı set edilmemiş) tetiklemeden güvenle null döner.
     */
    private function parseOptionalPositiveInt(Request $request, string $field): ?int
    {
        $raw = trim((string) $request->request->get($field, ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /** @return array{label: string, title: string, description: string, metaDescription: string} */
    private function forumHomeContext(): array
    {
        return [
            'label' => (string) $this->settingsRegistry->get('forum.home_label', 'Topluluk'),
            'title' => (string) $this->settingsRegistry->get('forum.home_title', 'Forum'),
            'description' => (string) $this->settingsRegistry->get('forum.home_description', 'Sorularınızı sorun, deneyimlerinizi paylaşın, tartışmalara katılın.'),
            'metaDescription' => (string) $this->settingsRegistry->get('forum.home_meta_description', 'Topluluk forumu: sorular, tartışmalar ve duyurular.'),
        ];
    }
}
