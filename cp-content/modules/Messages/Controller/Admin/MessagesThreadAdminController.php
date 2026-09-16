<?php

declare(strict_types=1);

namespace Modules\Messages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Pagination\Paginator;
use App\Entity\User;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Repository\MessageRepository;
use Modules\Messages\Repository\MessageThreadRepository;
use Modules\Messages\Service\MessagesDeniedException;
use Modules\Messages\Service\MessagesThreadManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/messages/threads', name: 'admin_messages_threads_')]
#[IsGranted('messages.moderate')]
final class MessagesThreadAdminController extends AbstractController
{
    private const CSRF = 'admin_messages_threads';

    public function __construct(
        private readonly MessageThreadRepository $threads,
        private readonly MessageRepository $messages,
        private readonly MessagesThreadManager $manager,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'messages.menu.threads', icon: 'heroicons:inbox', panel: 'studio', priority: 34, capability: 'messages.moderate', parent: 'admin_messages_dashboard')]
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $result = $this->paginator->paginate(
            $this->threads->createAdminQueryBuilder($search !== '' ? $search : null, (string) $request->query->get('context', '') ?: null),
            $request->query->getInt('page', 1),
            25,
        );

        return $this->render('@MessagesModule/admin/threads/index.html.twig', [
            'result' => $result,
            'search' => $search,
            'context' => (string) $request->query->get('context', ''),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $thread = $this->threads->find($id);
        if (!$thread instanceof MessageThread) {
            throw new NotFoundHttpException();
        }

        return $this->render('@MessagesModule/admin/threads/show.html.twig', [
            'thread' => $thread,
            'messages' => $this->messages->createThreadQueryBuilder($thread, includeDeleted: true)
                ->getQuery()
                ->getResult(),
            'csrfToken' => self::CSRF,
        ]);
    }

    #[Route('/{id}/kapat', name: 'close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(Request $request, int $id): Response
    {
        $this->assertCsrf($request);
        $thread = $this->requireThread($id);
        $user = $this->moderator();

        try {
            if ($request->request->getBoolean('reopen')) {
                $this->manager->reopen($thread);
            } else {
                $this->manager->close($thread, $user);
            }
            $this->addFlash('success', $this->translator->trans('messages.admin.flash.thread_updated'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('admin_messages_threads_show', ['id' => $thread->getId()]);
    }

    #[Route('/{id}/mesaj/{messageId}/sil', name: 'delete_message', methods: ['POST'], requirements: ['id' => '\d+', 'messageId' => '\d+'])]
    public function deleteMessage(Request $request, int $id, int $messageId): Response
    {
        $this->assertCsrf($request);
        $thread = $this->requireThread($id);
        $message = $this->messages->find($messageId);

        if (!$message instanceof Message || $message->getThread()->getId() !== $thread->getId()) {
            throw new NotFoundHttpException();
        }

        try {
            $this->manager->deleteMessage($message, $this->moderator());
            $this->addFlash('success', $this->translator->trans('messages.flash.deleted'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('admin_messages_threads_show', ['id' => $thread->getId()]);
    }

    private function requireThread(int $id): MessageThread
    {
        $thread = $this->threads->find($id);
        if (!$thread instanceof MessageThread) {
            throw new NotFoundHttpException();
        }

        return $thread;
    }

    private function moderator(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('messages.error.invalid_csrf'));
        }
    }
}
