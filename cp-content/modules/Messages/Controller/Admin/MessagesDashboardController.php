<?php

declare(strict_types=1);

namespace Modules\Messages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use Modules\Messages\Repository\MessageReportRepository;
use Modules\Messages\Service\MessagesStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/messages', name: 'admin_messages_')]
#[IsGranted('messages.moderate')]
final class MessagesDashboardController extends AbstractController
{
    public function __construct(
        private readonly MessagesStatsService $stats,
        private readonly MessageReportRepository $reports,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'messages.menu.root', icon: 'heroicons:chat-bubble-left-right', panel: 'studio', priority: 33, capability: 'messages.moderate', group: 'studio.group.content')]
    public function index(): Response
    {
        return $this->render('@MessagesModule/admin/dashboard.html.twig', [
            'stats' => $this->stats->overview(),
            'topSenders' => $this->stats->topSenders(),
            'openReports' => $this->reports->createQueueQueryBuilder(null)
                ->andWhere('r.status IN (:open)')
                ->setParameter('open', ['open', 'reviewing'])
                ->setMaxResults(8)
                ->getQuery()
                ->getResult(),
        ]);
    }
}
