<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Inbox\InboxPulse;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lightweight XenForo-style heartbeat. The theme polls this while the tab is
 * visible so badges, flyouts and the title prefix update without a refresh.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class InboxPulseController extends AbstractController
{
    public function __construct(
        private readonly InboxPulse $inboxPulse,
    ) {
    }

    #[Route('/hesap/nabiz', name: 'account_inbox_pulse', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $response = new JsonResponse($this->inboxPulse->forUser($user));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Accel-Expires', '0');

        return $response;
    }
}
