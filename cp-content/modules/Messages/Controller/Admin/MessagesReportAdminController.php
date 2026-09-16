<?php

declare(strict_types=1);

namespace Modules\Messages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Pagination\Paginator;
use App\Entity\User;
use Modules\Messages\Entity\MessageReport;
use Modules\Messages\Repository\MessageReportRepository;
use Modules\Messages\Service\MessagesDeniedException;
use Modules\Messages\Service\MessagesModerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/messages/reports', name: 'admin_messages_reports_')]
#[IsGranted('messages.report.moderate')]
final class MessagesReportAdminController extends AbstractController
{
    private const CSRF = 'admin_messages_reports';

    public function __construct(
        private readonly MessageReportRepository $reports,
        private readonly MessagesModerationService $moderation,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'messages.menu.reports', icon: 'heroicons:flag', panel: 'studio', priority: 35, capability: 'messages.report.moderate', parent: 'admin_messages_dashboard')]
    public function index(Request $request): Response
    {
        $status = (string) $request->query->get('status', MessageReport::STATUS_OPEN);
        if ($status === 'all') {
            $status = '';
        }

        $result = $this->paginator->paginate(
            $this->reports->createQueueQueryBuilder($status !== '' ? $status : null),
            $request->query->getInt('page', 1),
            25,
        );

        return $this->render('@MessagesModule/admin/reports/index.html.twig', [
            'result' => $result,
            'status' => $status,
            'openCount' => $this->reports->countOpen(),
            'csrfToken' => self::CSRF,
        ]);
    }

    #[Route('/{id}/karar', name: 'decide', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('messages.error.invalid_csrf'));
        }

        $report = $this->reports->find($id);
        if (!$report instanceof MessageReport) {
            throw new NotFoundHttpException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $note = (string) $request->request->get('note', '');

        try {
            if ($request->request->get('decision') === 'dismiss') {
                $this->moderation->dismiss($report, $user, $note);
            } else {
                $this->moderation->resolve($report, $user, $note);
            }
            $this->addFlash('success', $this->translator->trans('messages.admin.flash.report_updated'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('admin_messages_reports_index');
    }
}
