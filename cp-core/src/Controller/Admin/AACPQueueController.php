<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Queue\QueueStatusService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Dual-queue ops screen: Messenger (mail/notifications) + platform AsyncJob (webhooks).
 */
final class AACPQueueController
{
    private const CSRF = 'aacp_queue_action';

    public function __construct(
        private readonly Environment $twig,
        private readonly QueueStatusService $queueStatus,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/queue', name: 'aacp_queue', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.queue.menu', icon: 'heroicons:queue-list', panel: 'aacp', priority: 35, capability: 'system.queue.manage', group: 'aacp.group.genadset')]
    #[IsGranted('system.queue.manage')]
    public function index(): Response
    {
        $summary = $this->queueStatus->summary();

        return new Response($this->twig->render('aacp/queue/index.html.twig', [
            'summary' => $summary,
            'messengerPending' => $this->queueStatus->listMessenger('async', 40),
            'messengerFailed' => $this->queueStatus->listMessenger('failed', 40),
            'platformJobs' => $this->queueStatus->listPlatformJobs(40),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF)->getValue(),
            'workerHint' => $this->translator->trans('aacp.queue.worker_hint'),
        ]));
    }

    #[Route('/aacp/queue/messenger/{id}/retry', name: 'aacp_queue_messenger_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.queue.manage')]
    public function retryMessenger(int $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        if (!$this->queueStatus->retryFailedMessenger($id)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.queue.retry_failed'));
        }

        return new RedirectResponse('/aacp/queue#failed');
    }

    #[Route('/aacp/queue/messenger/{id}/delete', name: 'aacp_queue_messenger_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.queue.manage')]
    public function deleteMessenger(int $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->queueStatus->deleteMessenger($id);

        return new RedirectResponse('/aacp/queue');
    }

    private function assertCsrf(Request $request): void
    {
        $token = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF, $token))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}
