<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Notification\Repository\NotificationRepository;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountNotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/hesap/bildirimler', name: 'account_notifications', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireUser();
        $rows = $this->notifications->findForUser($user, 50);
        $unread = $this->notifications->countUnread($user);

        return $this->render('account/notifications/index.html.twig', [
            'notifications' => $rows,
            'unread' => $unread,
        ]);
    }

    #[Route('/hesap/bildirimler/hepsini-okundu', name: 'account_notifications_mark_all', methods: ['POST'])]
    public function markAll(Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        if (!$this->isCsrfTokenValid('account_notifications', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->notifications->markAllRead($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'notification.flash.marked_all_read');

        return $this->redirectToRoute('account_notifications');
    }

    #[Route('/hesap/bildirimler/{id}/okundu', name: 'account_notification_mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markRead(int $id, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        if (!$this->isCsrfTokenValid('account_notifications', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $notification = $this->notifications->find($id);
        if ($notification === null || $notification->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $notification->markRead();
        $this->entityManager->flush();

        return $this->redirectToRoute('account_notifications');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
