<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Settings\SettingsRegistry;
use App\Core\Pagination\Paginator;
use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumDictionary;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Service\ForumAccessService;
use Modules\Forum\Service\ForumDraftService;
use Modules\Forum\Service\ForumInlineModerationService;
use Modules\Forum\Service\ForumModerationLogService;
use Modules\Forum\Service\ForumPollService;
use Modules\Forum\Service\ForumSplitService;
use Modules\Forum\Service\ForumUnreadService;
use Modules\Forum\Service\ForumWatchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ForumToolsController extends AbstractController
{
    public function __construct(
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumWatchService $watchService,
        private readonly ForumPollService $pollService,
        private readonly ForumAccessService $accessService,
        private readonly UserRepository $userRepository,
        private readonly ForumDraftService $draftService,
        private readonly ForumUnreadService $unreadService,
        private readonly ForumSplitService $splitService,
        private readonly ForumModerationLogService $moderationLog,
        private readonly ForumInlineModerationService $inlineModeration,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forums/inline-mod', name: 'forum_inline_mod', methods: ['POST'], priority: 5)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function inlineMod(Request $request): Response
    {
        $back = $this->safeBackUrl($request);

        if (!$this->isCsrfTokenValid('forum_inline_mod', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('site.forum.csrf_invalid'));

            return $this->redirect($back);
        }

        /** @var User $user */
        $user = $this->getUser();
        $action = trim((string) $request->request->get('imod_action'));
        if ($action === '') {
            $this->addFlash('error', $this->translator->trans('forum.imod.choose_action'));

            return $this->redirect($back);
        }

        try {
            $this->assertInlineModCapability($action);
            $this->assertInlineModScope($action, (string) $request->request->get('imod_scope'));
            $result = $this->inlineModeration->execute(
                $user,
                $action,
                array_map('intval', (array) $request->request->all('topic_ids')),
                array_map('intval', (array) $request->request->all('post_ids')),
                $this->optionalPositiveInt($request, 'target_section_id'),
                $this->optionalPositiveInt($request, 'merge_target_topic_id'),
                $request->request->getBoolean('keep_redirect'),
                trim((string) $request->request->get('split_title')) ?: null,
            );
        } catch (AccessDeniedHttpException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirect($back);
        } catch (\InvalidArgumentException $e) {
            $key = $e->getMessage();
            $this->addFlash('error', str_starts_with($key, 'forum.') ? $this->translator->trans($key) : $key);

            return $this->redirect($back);
        }

        $this->addFlash('success', $this->translator->trans($result['messageKey'], $result['messageParams']));

        if (($result['redirectTopicId'] ?? null) !== null) {
            return $this->redirectToRoute('forum_topic', [
                'topicId' => $result['redirectTopicId'],
                'slug' => $result['redirectSlug'] ?? 'konu',
            ]);
        }

        return $this->redirect($back);
    }

    private function safeBackUrl(Request $request): string
    {
        $origin = $request->getSchemeAndHttpHost();
        foreach ([$request->request->get('return_url'), $request->headers->get('referer')] as $candidate) {
            $url = (string) $candidate;
            if ($url !== '' && (str_starts_with($url, $origin.'/') || $url === $origin)) {
                return $url;
            }
        }

        return $this->generateUrl('forum_index');
    }

    /**
     * @return list<string>
     */
    private function topicInlineActions(): array
    {
        return ['lock', 'unlock', 'sticky', 'unsticky', 'move', 'merge', 'hide', 'delete', 'restore'];
    }

    /**
     * @return list<string>
     */
    private function postInlineActions(): array
    {
        return ['split', 'merge_posts', 'delete_posts'];
    }

    private function assertInlineModScope(string $action, string $scope): void
    {
        $allowed = $scope === 'posts' ? $this->postInlineActions() : $this->topicInlineActions();
        if (!\in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('forum.imod.invalid_action');
        }
    }

    private function optionalPositiveInt(Request $request, string $key): ?int
    {
        $raw = $request->request->get($key);
        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private function assertInlineModCapability(string $action): void
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
            'merge' => 'forum.topic.moderate',
            'delete' => 'forum.topic.moderate',
            'hide' => 'forum.topic.moderate',
            'restore' => 'forum.topic.moderate',
            'delete_posts' => 'forum.post.delete.any',
            'merge_posts' => 'forum.topic.moderate',
            'split' => 'forum.thread.split',
        ];
        $required = $map[$action] ?? 'forum.topic.moderate';
        if (!$this->isGranted($required)) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.moderate.invalid_action'));
        }
    }

    #[Route('/forums/thread/{topicId}/watch', name: 'forum_topic_watch', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function watch(Request $request, int $topicId): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertValidCsrf($request, 'forum_watch');
        $topic = $this->findTopicOrFail($topicId);
        $watching = $this->watchService->toggle($topic, $user);
        $this->addFlash('success', $this->translator->trans($watching ? 'forum.watch.on' : 'forum.watch.off'));

        return $this->redirectToRoute('forum_topic', ['topicId' => $topicId, 'slug' => $topic->getSlug() ?? 'konu']);
    }

    #[Route('/forums/thread/{topicId}/poll', name: 'forum_poll_vote', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function vote(Request $request, int $topicId): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertValidCsrf($request, 'forum_poll_vote');
        $topic = $this->findTopicOrFail($topicId);
        $poll = $this->pollService->findForTopic($topic);
        if ($poll === null) {
            throw new NotFoundHttpException($this->translator->trans('forum.poll.missing'));
        }

        $raw = $request->request->all('option_ids');
        if ($raw === []) {
            $single = $request->request->getInt('option_id');
            $raw = $single > 0 ? [$single] : [];
        }

        try {
            $ok = $this->pollService->vote($poll, $user, is_array($raw) ? $raw : []);
            $this->addFlash($ok ? 'success' : 'error', $this->translator->trans($ok ? 'forum.poll.voted' : 'forum.poll.vote_failed'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));
        }

        return $this->redirectToRoute('forum_topic', ['topicId' => $topicId, 'slug' => $topic->getSlug() ?? 'konu']);
    }

    #[Route('/forums/member/{userId}/block', name: 'forum_block_user', methods: ['POST'], requirements: ['userId' => '\d+'], priority: 5)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function blockUser(Request $request, int $userId): Response
    {
        $this->assertValidCsrf($request, 'forum_block');
        $target = $this->userRepository->find($userId);
        if (!$target instanceof User) {
            throw new NotFoundHttpException($this->translator->trans('forum.ignore.user_not_found'));
        }

        /** @var User $user */
        $user = $this->getUser();
        try {
            if ($request->request->getBoolean('unblock')) {
                $this->accessService->unblock($user, $target);
                $this->addFlash('success', $this->translator->trans('forum.ignore.unblocked'));
            } else {
                $this->accessService->block($user, $target);
                $this->addFlash('success', $this->translator->trans('forum.ignore.blocked'));
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));
        }

        return $this->redirect($this->safeBackUrl($request));
    }

    #[Route('/forums/drafts', name: 'forum_drafts', methods: ['GET'], priority: 4)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function drafts(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('@Theme/forum/drafts.html.twig', [
            'drafts' => $this->draftService->listForUser($user),
            'forumHome' => ['title' => (string) $this->settingsRegistry->get('forum.home_title', 'Forum')],
        ]);
    }

    #[Route('/forums/konularim', name: 'forum_my_topics', methods: ['GET'], priority: 4)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function myTopics(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Was a hard cap of 50 with no way to reach topic 51. Paginated on the
        // same per-page setting the board list uses, so one number in Studio
        // governs every topic list.
        $qb = $this->topicRepository->createPublicByAuthorQueryBuilder($user)
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('t.prefix', 'prefix')->addSelect('prefix')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp');

        return $this->render('@Theme/forum/my_topics.html.twig', [
            'topics' => $this->paginator->paginate($qb, $request->query->getInt('page', 1), $this->topicsPerPage()),
            'summary' => $this->topicRepository->summaryForAuthor($user),
            // The row markup is the board's, so it needs the same two numbers the
            // board passes: one decides the "hot" icon, the other the page a
            // "last reply" link has to jump to.
            'hotThreshold' => (int) $this->settingsRegistry->get('forum.hot_topic_threshold', ForumDictionary::HOT_TOPIC_POST_THRESHOLD),
            'postsPerPage' => (int) $this->settingsRegistry->get('forum.posts_per_page', ForumDictionary::DEFAULT_POSTS_PER_PAGE),
            'forumHome' => ['title' => (string) $this->settingsRegistry->get('forum.home_title', 'Forum')],
        ]);
    }

    /**
     * Topics per page, from Studio -> Forum -> Settings.
     */
    private function topicsPerPage(): int
    {
        $perPage = (int) $this->settingsRegistry->get('forum.threads_per_page', 30);

        return max(1, $perPage);
    }

    #[Route('/forums/drafts/save', name: 'forum_draft_save', methods: ['POST'], priority: 4)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function saveDraft(Request $request): JsonResponse
    {
        if (!$this->draftService->isEnabled()) {
            return new JsonResponse(['ok' => false], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->assertValidCsrf($request, 'forum_draft');
        $body = trim((string) $request->request->get('body'));
        $title = trim((string) $request->request->get('title'));
        $topicId = $request->request->getInt('topic_id');
        $sectionId = $request->request->getInt('section_id');

        if ($topicId > 0) {
            $topic = $this->findTopicOrFail($topicId);
            $this->draftService->saveReply($user, $topic, $body);
        } elseif ($sectionId > 0) {
            $section = $this->sectionRepository->find($sectionId);
            if (!$section instanceof ForumSection) {
                throw new NotFoundHttpException();
            }
            $this->draftService->saveNewTopic($user, $section, $title, $body);
        } else {
            return new JsonResponse(['ok' => false], 400);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/forums/okunmamis', name: 'forum_unread', methods: ['GET'], priority: 4)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function unread(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('@Theme/forum/unread.html.twig', [
            // Not paginated: the list is built by scanning the newest topics for
            // ones this member has not read, so "page 2" would mean a different
            // query, not an offset. The size follows the same Studio setting.
            'topics' => $this->unreadService->unreadTopics($user, $this->topicsPerPage()),
            'forumHome' => ['title' => (string) $this->settingsRegistry->get('forum.home_title', 'Forum')],
        ]);
    }

    #[Route('/forums/okundu', name: 'forum_mark_read', methods: ['POST'], priority: 4)]
    #[IsGranted('IS_AUTHENTICATED')]
    public function markRead(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertValidCsrf($request, 'forum_mark_read');
        $sectionId = $request->request->getInt('section_id');
        if ($sectionId > 0) {
            $section = $this->sectionRepository->find($sectionId);
            if ($section instanceof ForumSection) {
                $this->unreadService->markSectionRead($section, $user);
            }
        } else {
            $this->unreadService->markAllRead($user);
        }

        $this->addFlash('success', $this->translator->trans('forum.unread.marked'));

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('forum_index'));
    }

    #[Route('/forums/thread/{topicId}/split', name: 'forum_topic_split', methods: ['POST'], requirements: ['topicId' => '\d+'])]
    #[IsGranted('forum.topic.moderate')]
    public function split(Request $request, int $topicId): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertValidCsrf($request, 'forum_split');
        $topic = $this->findTopicOrFail($topicId);
        $title = trim((string) $request->request->get('title'));
        $postIds = array_map('intval', (array) $request->request->all('post_ids'));
        $targetId = $request->request->getInt('target_section_id');
        $target = $targetId > 0 ? $this->sectionRepository->find($targetId) : null;

        try {
            $newTopic = $this->splitService->split(
                $topic,
                $postIds,
                $title,
                $user,
                $target instanceof ForumSection ? $target : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->moderationLog->record($user, 'split', 'topic', (int) $topic->getId(), [
            'new_topic_id' => $newTopic->getId(),
            'title' => $title,
        ]);
        $this->addFlash('success', $this->translator->trans('forum.split.done'));

        return $this->redirectToRoute('forum_topic', ['topicId' => $newTopic->getId(), 'slug' => $newTopic->getSlug() ?? 'konu']);
    }

    private function findTopicOrFail(int $topicId): ForumTopic
    {
        $topic = $this->topicRepository->find($topicId);
        if (!$topic instanceof ForumTopic) {
            throw new NotFoundHttpException($this->translator->trans('site.forum.topic_not_found'));
        }

        return $topic;
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException($this->translator->trans('site.forum.csrf_invalid'));
        }
    }
}
