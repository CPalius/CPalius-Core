<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Pagination\Paginator;
use Modules\Forum\Entity\ForumNotification;
use App\Entity\User;
use Modules\Forum\Repository\ForumNotificationRepository;
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
        private readonly ForumNotificationRepository $notificationRepository,
        private readonly ForumNotificationService $notificationService,
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
            ForumNotification::TYPE_REPLY,
            ForumNotification::TYPE_THREAD_REPLY,
            ForumNotification::TYPE_QUOTE,
            ForumNotification::TYPE_MENTION,
            ForumNotification::TYPE_REACTION,
            ForumNotification::TYPE_DISLIKE,
            ForumNotification::TYPE_REPUTATION,
        ];
        if (!\in_array($type, $allowed, true)) {
            $type = 'all';
        }

        $qb = $this->notificationRepository->createForUserQueryBuilder($user, $type === 'all' ? null : $type);
        $items = $this->paginator->paginate($qb, $request->query->getInt('page', 1), self::PER_PAGE);

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
        $notification = $this->notificationRepository->find($id);
        if (!$notification instanceof ForumNotification || $notification->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $this->notificationService->markRead($notification);
        $data = $notification->getData();

        $topicId = isset($data['topic_id']) ? (int) $data['topic_id'] : 0;
        $slug = isset($data['topic_slug']) ? (string) $data['topic_slug'] : 'konu';
        $postId = isset($data['post_id']) ? (int) $data['post_id'] : 0;

        if ($topicId > 0) {
            $url = $this->generateUrl('forum_topic', ['topicId' => $topicId, 'slug' => $slug !== '' ? $slug : 'konu']);
            if ($postId > 0) {
                $url .= '#post'.$postId;
            }

            return $this->redirect($url);
        }

        if ($notification->getType() === ForumNotification::TYPE_REPUTATION) {
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
