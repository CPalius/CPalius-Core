<?php

declare(strict_types=1);

namespace Modules\Messages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Entity\User;
use Modules\Messages\Repository\MessageRestrictionRepository;
use Modules\Messages\Service\MessagesDeniedException;
use Modules\Messages\Service\MessagesModerationService;
use Modules\Messages\Service\MessagesStatsService;
use Modules\Messages\Service\MessagesUserLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/messages/quota', name: 'admin_messages_quota_')]
#[IsGranted('messages.moderate')]
final class MessagesQuotaAdminController extends AbstractController
{
    private const CSRF = 'admin_messages_quota';

    public function __construct(
        private readonly MessagesUserLookup $users,
        private readonly MessagesStatsService $stats,
        private readonly MessagesModerationService $moderation,
        private readonly MessageRestrictionRepository $restrictions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    #[CpAdminMenu(label: 'messages.menu.quota', icon: 'heroicons:chart-bar', panel: 'studio', priority: 36, capability: 'messages.moderate', parent: 'admin_messages_dashboard')]
    public function index(Request $request): Response
    {
        $subject = $this->users->resolve((string) $request->query->get('user', $request->request->get('user', '')));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                throw new BadRequestHttpException($this->translator->trans('messages.error.invalid_csrf'));
            }

            $actor = $this->getUser();
            if (!$actor instanceof User || !$subject instanceof User) {
                throw $this->createAccessDeniedException();
            }

            try {
                if ($request->request->get('action') === 'lift') {
                    $this->moderation->liftRestriction($subject);
                    $this->addFlash('success', $this->translator->trans('messages.admin.flash.restriction_lifted'));
                } else {
                    $hours = max(0, $request->request->getInt('hours'));
                    $expires = $hours > 0 ? (new \DateTimeImmutable())->modify('+'.$hours.' hours') : null;
                    $this->moderation->restrict($subject, $actor, (string) $request->request->get('reason', ''), $expires);
                    $this->addFlash('success', $this->translator->trans('messages.admin.flash.restricted'));
                }
            } catch (MessagesDeniedException $exception) {
                $this->addFlash('error', $this->translator->trans($exception->translationKey));
            }

            return $this->redirectToRoute('admin_messages_quota_index', [
                'user' => $subject->getUsername() ?: (string) $subject->getId(),
            ]);
        }

        return $this->render('@MessagesModule/admin/quota/index.html.twig', [
            'subject' => $subject,
            'quota' => $subject instanceof User ? $this->stats->quotaFor($subject) : null,
            'restriction' => $subject instanceof User ? $this->restrictions->findForUser($subject) : null,
            'activeRestrictions' => $this->restrictions->createActiveQueryBuilder()->setMaxResults(20)->getQuery()->getResult(),
            'query' => (string) $request->query->get('user', ''),
            'csrfToken' => self::CSRF,
        ]);
    }
}
