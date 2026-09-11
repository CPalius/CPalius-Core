<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Logging\Entity\LogEntry;
use App\Core\Logging\Repository\LogEntryRepository;
use App\Core\Mail\CpMailerService;
use App\Core\Mail\Entity\MailLog;
use App\Core\Mail\Repository\MailLogRepository;
use App\Core\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Application watchdog + outbound mail audit (T3.5).
 */
#[Route('/aacp/logs', name: 'aacp_logs_')]
#[IsGranted('system.logs.manage')]
final class AACPLogController extends AbstractController
{
    private const CSRF_MAIL = 'aacp_logs_mail';
    private const PER_PAGE = 40;

    public function __construct(
        private readonly LogEntryRepository $logEntries,
        private readonly MailLogRepository $mailLogs,
        private readonly CpMailerService $mailer,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.logs.menu', icon: 'heroicons:document-text', panel: 'aacp', priority: 34, capability: 'system.logs.manage', parent: 'aacp_tools')]
    public function index(Request $request): Response
    {
        $filters = [
            'level' => $this->nullableString($request->query->get('level')),
            'channel' => $this->nullableString($request->query->get('channel')),
            'q' => $this->nullableString($request->query->get('q')),
        ];

        $items = $this->paginator->paginate(
            $this->logEntries->createFilteredQueryBuilder($filters),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('aacp/logs/index.html.twig', [
            'items' => $items,
            'filters' => $filters,
            'channels' => $this->logEntries->distinctChannels(),
            'levels' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'], priority: -10)]
    public function show(int $id): Response
    {
        $entry = $this->logEntries->find($id);
        if (!$entry instanceof LogEntry) {
            throw new NotFoundHttpException($this->translator->trans('aacp.logs.error.not_found'));
        }

        return $this->render('aacp/logs/show.html.twig', [
            'entry' => $entry,
        ]);
    }

    #[Route('/mail', name: 'mail', methods: ['GET'], priority: 10)]
    public function mail(Request $request): Response
    {
        $filters = [
            'status' => $this->nullableString($request->query->get('status')),
            'q' => $this->nullableString($request->query->get('q')),
        ];

        $items = $this->paginator->paginate(
            $this->mailLogs->createFilteredQueryBuilder($filters),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('aacp/logs/mail.html.twig', [
            'items' => $items,
            'filters' => $filters,
            'statuses' => [MailLog::STATUS_QUEUED, MailLog::STATUS_SENT, MailLog::STATUS_FAILED],
        ]);
    }

    #[Route('/mail/{id}/resend', name: 'mail_resend', methods: ['POST'], requirements: ['id' => '\d+'], priority: 10)]
    public function resend(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_MAIL, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $log = $this->mailLogs->find($id);
        if (!$log instanceof MailLog) {
            throw new NotFoundHttpException($this->translator->trans('aacp.logs.mail.error.not_found'));
        }

        try {
            $this->mailer->resend($log, immediate: false);
            $this->addFlash('success', $this->translator->trans('aacp.logs.mail.flash.resent'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->translator->trans('aacp.logs.mail.flash.resend_failed', [
                'error' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('aacp_logs_mail');
    }

    private function nullableString(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }
        $value = trim($raw);

        return $value !== '' ? $value : null;
    }
}
