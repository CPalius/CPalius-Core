<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Logging\Entity\LogEntry;
use App\Core\Logging\LogPurgeService;
use App\Core\Settings\SettingsRegistry;
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
    private const CSRF_PURGE = 'aacp_logs_purge';
    private const PER_PAGE = 40;

    public function __construct(
        private readonly LogEntryRepository $logEntries,
        private readonly MailLogRepository $mailLogs,
        private readonly CpMailerService $mailer,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
        private readonly LogPurgeService $purger,
        private readonly SettingsRegistry $settings,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.logs.menu', icon: 'heroicons:document-text', panel: 'aacp', priority: 84, capability: 'system.logs.manage', parent: 'aacp_hub_maintenance')]
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
            'counts' => $this->purger->counts(),
            'purgeToken' => $this->csrfToken(self::CSRF_PURGE),
            'retention' => $this->retentionWindows(),
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


    /**
     * Applies every store's configured retention window now, instead of waiting
     * for the nightly cron.
     *
     * This is the button to reach for first: it frees space without discarding
     * the recent history an operator may still need to read.
     */
    #[Route('/purge-aged', name: 'purge_aged', methods: ['POST'], priority: 10)]
    #[IsGranted('system.logs.purge')]
    public function purgeAged(Request $request): RedirectResponse
    {
        $this->assertPurgeToken($request);

        $windows = $this->retentionWindows();

        $deleted = $this->purger->purgeByRetention(
            $windows['app'],
            $windows['mail'],
            $windows['audit'],
            $windows['telemetry'],
        );

        $this->addFlash('success', $this->translator->trans('aacp.logs.purge.flash.aged', [
            'total' => array_sum($deleted),
        ]));

        return $this->redirectToRoute('aacp_logs_index');
    }

    /**
     * Empties a store completely, or every store when "store" is "all".
     *
     * Behind its own capability rather than system.logs.manage: being allowed to
     * READ an audit trail is not the same as being allowed to ERASE one, and
     * collapsing the two would hand every log reader the ability to destroy the
     * record of what they did.
     */
    #[Route('/purge', name: 'purge', methods: ['POST'], priority: 10)]
    #[IsGranted('system.logs.purge')]
    public function purge(Request $request): RedirectResponse
    {
        $this->assertPurgeToken($request);

        $store = (string) $request->request->get('store', 'all');

        if ($store !== 'all' && !$this->purger->isKnownStore($store)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.logs.purge.error.unknown_store'));
        }

        $deleted = $this->purger->purge($store === 'all' ? null : $store);

        $this->addFlash('success', $this->translator->trans('aacp.logs.purge.flash.all', [
            'total' => array_sum($deleted),
        ]));

        return $this->redirectToRoute('aacp_logs_index');
    }

    private function assertPurgeToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_PURGE, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }

    private function csrfToken(string $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    /**
     * @return array{app: int, mail: int, audit: int, telemetry: array<string, int>}
     */
    private function retentionWindows(): array
    {
        return [
            'app' => $this->days('logging.retention_days', 30),
            'mail' => $this->days('mail.log_retention_days', 90),
            'audit' => $this->days('audit.retention_days', 365),
            'telemetry' => [
                'info' => $this->days('telemetry.retention_info', 3),
                'warning' => $this->days('telemetry.retention_warning', 7),
                'critical' => $this->days('telemetry.retention_critical', 30),
                'threat' => $this->days('telemetry.retention_threat', 90),
            ],
        ];
    }

    private function days(string $key, int $fallback): int
    {
        $value = $this->settings->get($key, $fallback);
        $days = \is_numeric($value) ? (int) $value : $fallback;

        return max(1, min(3650, $days));
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
