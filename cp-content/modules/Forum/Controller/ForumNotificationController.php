<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Pagination\PaginatedResult;
use App\Core\Pagination\Paginator;
use App\Entity\User;
use Modules\Forum\Notification\ForumNotificationType;
use Modules\Forum\Notification\ForumInboxItem;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Service\ForumNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED')]
final class ForumNotificationController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ForumNotificationService $notificationService,
        private readonly ForumTopicRepository $topicRepository,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forum/bildirimler', name: 'forum_notifications', methods: ['GET'], priority: 10)]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $type = (string) $request->query->get('type', 'all');
        $allowed = [
            'all',
            ForumNotificationType::REPLY,
            ForumNotificationType::THREAD_REPLY,
            ForumNotificationType::QUOTE,
            ForumNotificationType::MENTION,
            ForumNotificationType::REACTION,
            ForumNotificationType::DISLIKE,
            ForumNotificationType::REPUTATION,
            ForumNotificationType::WATCH,
        ];
        if (!\in_array($type, $allowed, true)) {
            $type = 'all';
        }

        $qb = $this->notificationService->createInboxQueryBuilder($user, $type === 'all' ? null : $type);
        $page = $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::PER_PAGE);
        $items = new PaginatedResult(
            array_map(
                fn ($n) => $this->notificationService->wrap($n),
                $page->getItems(),
            ),
            $page->getTotalItems(),
            $page->getCurrentPage(),
            $page->getPerPage(),
        );

        return $this->render('@Theme/forum/notifications.html.twig', [
            'items' => $items,
            'type' => $type,
            'unreadCount' => $this->notificationService->countUnread($user),
        ]);
    }

    #[Route('/forum/bildirimler/okundu', name: 'forum_notifications_mark_read', methods: ['POST'], priority: 10)]
    public function markAllRead(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertValidCsrf($request, 'forum_notifications_mark_read');
        $this->notificationService->markAllRead($user);
        $this->addFlash('success', $this->translator->trans('site.forum.notifications.marked_read'));

        return $this->redirectToRoute('forum_notifications');
    }

    #[Route('/forum/bildirim/{id}/git', name: 'forum_notification_go', methods: ['GET'], requirements: ['id' => '\d+'], priority: 10)]
    public function go(int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $notification = $this->notificationService->findOwned($user, $id);
        if ($notification === null) {
            throw $this->createNotFoundException();
        }

        $this->notificationService->markRead($notification);
        $item = new ForumInboxItem($notification);
        $data = $item->getData();

        $topicId = isset($data['topic_id']) ? (int) $data['topic_id'] : 0;
        $slug = isset($data['topic_slug']) ? (string) $data['topic_slug'] : 'konu';
        $postId = isset($data['post_id']) ? (int) $data['post_id'] : 0;

        if ($topicId > 0) {
            $topic = $this->topicRepository->find($topicId);
            $locale = $topic?->getLocale();
            $params = ['topicId' => $topicId, 'slug' => $slug !== '' ? $slug : 'konu'];
            if (\is_string($locale) && $locale !== '') {
                $params['_locale'] = $locale;
            }
            $url = $this->generateUrl('forum_topic', $params);
            if ($postId > 0) {
                $url .= '#post'.$postId;
            }

            return $this->redirect($url);
        }

        if ($item->getType() === ForumNotificationType::REPUTATION) {
            return $this->redirectToRoute('forum_profile', ['username' => $user->getProfileSlug(), 'tab' => 'reputation']);
        }

        return $this->redirectToRoute('forum_notifications');
    }

    private function assertValidCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException($this->translator->trans('site.forum.csrf_invalid'));
        }
    }
}
