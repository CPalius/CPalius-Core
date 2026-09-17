<?php

declare(strict_types=1);

namespace Modules\Messages\Controller;

use App\Core\Account\UserAvatarService;
use App\Core\Pagination\Paginator;
use App\Core\TextFormat\TextFormatAccess;
use App\Core\TextFormat\TextFormatRegistry;
use App\Entity\User;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageReport;
use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Repository\MessageBlockRepository;
use Modules\Messages\Repository\MessageParticipantRepository;
use Modules\Messages\Repository\MessageRepository;
use Modules\Messages\Repository\MessageThreadRepository;
use Modules\Messages\Service\MessagesAccess;
use Modules\Messages\Service\MessagesBlockService;
use Modules\Messages\Service\MessagesConfig;
use Modules\Messages\Service\MessagesContext;
use Modules\Messages\Service\MessagesDeniedException;
use Modules\Messages\Service\MessagesModerationService;
use Modules\Messages\Service\MessagesQuota;
use Modules\Messages\Service\MessagesTemplateResolver;
use Modules\Messages\Service\MessagesThreadManager;
use Modules\Messages\Service\MessagesUserLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Member inbox. Every write goes through MessagesThreadManager.
 */
#[Route('/messages', name: 'messages_', priority: 5)]
#[IsGranted('messages.send')]
final class MessagesFrontController extends AbstractController
{
    private const CSRF = 'messages';

    public function __construct(
        private readonly MessageThreadRepository $threads,
        private readonly MessageRepository $messages,
        private readonly MessageParticipantRepository $participants,
        private readonly MessageBlockRepository $blocks,
        private readonly MessagesThreadManager $manager,
        private readonly MessagesBlockService $blockService,
        private readonly MessagesModerationService $moderation,
        private readonly MessagesAccess $access,
        private readonly MessagesConfig $config,
        private readonly MessagesQuota $quota,
        private readonly MessagesUserLookup $users,
        private readonly UserAvatarService $avatars,
        private readonly MessagesTemplateResolver $templates,
        private readonly TextFormatAccess $textFormats,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'inbox', methods: ['GET'])]
    public function inbox(Request $request): Response
    {
        return $this->listThreads($request, archived: false);
    }

    #[Route('/arsiv', name: 'archived', methods: ['GET'])]
    public function archived(Request $request): Response
    {
        return $this->listThreads($request, archived: true);
    }

    #[Route('/yaz', name: 'compose', methods: ['GET', 'POST'])]
    public function compose(Request $request): Response
    {
        $actor = $this->member();
        $context = MessagesContext::fromRequest($request);
        $recipient = $this->users->resolve((string) $request->get('to', ''));
        $quota = $this->quota->snapshot($actor);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $recipient = $this->users->resolve((string) $request->request->get('to', ''));
            $context = MessagesContext::fromRequest($request);

            if (!$recipient instanceof User) {
                $errors[] = 'messages.error.recipient_required';
            } else {
                try {
                    $message = $this->manager->startOrContinue(
                        $actor,
                        $recipient,
                        (string) $request->request->get('body', ''),
                        (string) $request->request->get('body_format', TextFormatRegistry::RESTRICTED),
                        $context,
                        (string) $request->request->get('subject', ''),
                    );

                    $this->addFlash('success', $this->translator->trans('messages.flash.sent'));

                    return $this->redirectToRoute('messages_thread', [
                        'publicId' => $message->getThread()->getPublicId(),
                    ]);
                } catch (MessagesDeniedException $exception) {
                    $errors[] = $exception->translationKey;
                }
            }
        }

        $denied = $recipient instanceof User ? $this->access->canStartThread($actor, $recipient) : null;

        return $this->render($this->templates->resolve('compose'), [
            'messagesParentLayout' => $this->templates->layout(),
            'recipient' => $recipient,
            'recipientAvatar' => $recipient instanceof User ? $this->avatars->resolveUrl($recipient) : null,
            'context' => $context,
            'quota' => $quota,
            'denied' => $denied,
            'errors' => $errors,
            'formats' => $this->textFormats->usableChoices([TextFormatRegistry::RESTRICTED, TextFormatRegistry::BASIC_HTML]),
            'csrfToken' => self::CSRF,
            'heroTitle' => $this->config->heroTitle(),
            'heroDescription' => $this->config->heroDescription(),
        ]);
    }

    #[Route('/kullanici', name: 'suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $actor = $this->member();
        $matches = $this->users->suggest((string) $request->query->get('q', ''), $actor);

        return new JsonResponse([
            'data' => array_map(fn (User $user): array => [
                'id' => $user->getId(),
                'name' => $user->getPublicDisplayName(),
                'username' => $user->getUsername() ?: (string) $user->getId(),
                'avatar' => $this->avatars->resolveUrl($user),
                'initial' => mb_strtoupper(mb_substr($user->getPublicDisplayName() !== '' ? $user->getPublicDisplayName() : '?', 0, 1)),
            ], $matches),
        ]);
    }

    #[Route('/okunmamis', name: 'unread', methods: ['GET'])]
    public function unread(): JsonResponse
    {
        return new JsonResponse(['unread' => $this->participants->unreadTotal($this->member())]);
    }

    /**
     * Clears the unread badge in one go.
     *
     * Declared before the {publicId} route so "hepsini-okundu" is never taken
     * for a thread id, and POST-only because it changes state.
     */
    #[Route('/hepsini-okundu', name: 'mark_all_read', methods: ['POST'], priority: 10)]
    public function markAllRead(Request $request): Response
    {
        $actor = $this->member();

        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('messages.error.invalid_csrf'));
        }

        $cleared = $this->manager->markAllRead($actor);

        // The header flyout posts this with fetch(); answering JSON there saves
        // a full page load just to drop a badge to zero.
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'cleared' => $cleared, 'unread' => 0]);
        }

        return $this->redirectToRoute('messages_inbox');
    }

    #[Route('/notify.mp3', name: 'notify_sound', methods: ['GET'], priority: 20)]
    public function notifySound(): Response
    {
        $mp3 = \dirname(__DIR__).'/Resources/assets/message-notify.mp3';
        $wav = \dirname(__DIR__).'/Resources/assets/message-notify.wav';
        $file = is_file($mp3) ? $mp3 : $wav;
        if (!is_file($file)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', str_ends_with($file, '.mp3') ? 'audio/mpeg' : 'audio/wav');
        $response->setAutoEtag();
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }

    #[Route('/engellenenler', name: 'blocked', methods: ['GET'])]
    public function blocked(): Response
    {
        $actor = $this->member();

        return $this->render($this->templates->resolve('blocked'), [
            'messagesParentLayout' => $this->templates->layout(),
            'blocks' => $this->blocks->listFor($actor),
            'csrfToken' => self::CSRF,
        ]);
    }

    #[Route('/{publicId}', name: 'thread', methods: ['GET', 'POST'], requirements: ['publicId' => '[a-f0-9]{16}'])]
    public function thread(Request $request, string $publicId): Response
    {
        $actor = $this->member();
        $thread = $this->requireThread($publicId, $actor);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            try {
                $this->manager->reply(
                    $thread,
                    $actor,
                    (string) $request->request->get('body', ''),
                    (string) $request->request->get('body_format', TextFormatRegistry::RESTRICTED),
                );
                $this->addFlash('success', $this->translator->trans('messages.flash.sent'));
            } catch (MessagesDeniedException $exception) {
                $this->addFlash('error', $this->translator->trans($exception->translationKey, $exception->parameters));
            }

            return $this->redirectToRoute('messages_thread', ['publicId' => $thread->getPublicId()]);
        }

        $this->manager->markRead($thread, $actor);

        $page = $request->query->getInt('page', 1);
        $result = $this->paginator->paginate(
            $this->messages->createThreadQueryBuilder($thread, includeDeleted: $this->access->canModerate()),
            $page,
            $this->config->messagesPerPage(),
        );

        $other = $thread->otherParticipant($actor);
        $participant = $thread->participantFor($actor);

        return $this->render($this->templates->resolve('thread'), [
            'messagesParentLayout' => $this->templates->layout(),
            'thread' => $thread,
            'result' => $result,
            'other' => $other,
            'participant' => $participant,
            'quota' => $this->quota->snapshot($actor),
            'canReply' => $this->access->canReply($actor, $thread),
            'formats' => $this->textFormats->usableChoices([TextFormatRegistry::RESTRICTED, TextFormatRegistry::BASIC_HTML]),
            'csrfToken' => self::CSRF,
            'reportReasons' => MessageReport::REASONS,
        ]);
    }

    #[Route('/{publicId}/arsivle', name: 'archive', methods: ['POST'], requirements: ['publicId' => '[a-f0-9]{16}'])]
    public function archive(Request $request, string $publicId): Response
    {
        $this->assertCsrf($request);
        $actor = $this->member();
        $thread = $this->requireThread($publicId, $actor);
        $archived = $request->request->getBoolean('archived', true);

        try {
            $this->manager->archive($thread, $actor, $archived);
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute($archived ? 'messages_archived' : 'messages_inbox');
    }

    #[Route('/{publicId}/sessiz', name: 'mute', methods: ['POST'], requirements: ['publicId' => '[a-f0-9]{16}'])]
    public function mute(Request $request, string $publicId): Response
    {
        $this->assertCsrf($request);
        $actor = $this->member();
        $thread = $this->requireThread($publicId, $actor);

        try {
            $this->manager->mute($thread, $actor, $request->request->getBoolean('muted', true));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('messages_thread', ['publicId' => $thread->getPublicId()]);
    }

    #[Route('/{publicId}/gizle', name: 'hide', methods: ['POST'], requirements: ['publicId' => '[a-f0-9]{16}'])]
    public function hide(Request $request, string $publicId): Response
    {
        $this->assertCsrf($request);
        $actor = $this->member();
        $thread = $this->requireThread($publicId, $actor);

        try {
            $this->manager->hide($thread, $actor);
            $this->addFlash('success', $this->translator->trans('messages.flash.hidden'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('messages_inbox');
    }

    #[Route('/{publicId}/mesaj/{id}/sil', name: 'delete_message', methods: ['POST'], requirements: ['publicId' => '[a-f0-9]{16}', 'id' => '\d+'])]
    public function deleteMessage(Request $request, string $publicId, int $id): Response
    {
        $this->assertCsrf($request);
        $actor = $this->member();
        $thread = $this->requireThread($publicId, $actor);
        $message = $this->messages->find($id);

        if (!$message instanceof Message || $message->getThread()->getId() !== $thread->getId()) {
            throw new NotFoundHttpException();
        }

        try {
            $this->manager->deleteMessage($message, $actor);
            $this->addFlash('success', $this->translator->trans('messages.flash.deleted'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('messages_thread', ['publicId' => $thread->getPublicId()]);
    }

    #[Route('/{publicId}/mesaj/{id}/bildir', name: 'report', methods: ['POST'], requirements: ['publicId' => '[a-f0-9]{16}', 'id' => '\d+'])]
    public function report(Request $request, string $publicId, int $id): Response
    {
        $this->assertCsrf($request);
        $actor = $this->member();
        $thread = $this->requireThread($publicId, $actor);
        $message = $this->messages->find($id);

        if (!$message instanceof Message || $message->getThread()->getId() !== $thread->getId()) {
            throw new NotFoundHttpException();
        }

        try {
            $this->moderation->report(
                $message,
                $actor,
                (string) $request->request->get('reason', MessageReport::REASON_OTHER),
                (string) $request->request->get('details', ''),
            );
            $this->addFlash('success', $this->translator->trans('messages.flash.reported'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('messages_thread', ['publicId' => $thread->getPublicId()]);
    }

    #[Route('/engelle/{id}', name: 'block', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function block(Request $request, int $id): Response
    {
        $this->assertCsrf($request);
        $target = $this->users->resolve((string) $id);
        if (!$target instanceof User) {
            throw new NotFoundHttpException();
        }

        try {
            $this->blockService->block($this->member(), $target, (string) $request->request->get('reason', ''));
            $this->addFlash('success', $this->translator->trans('messages.flash.blocked'));
        } catch (MessagesDeniedException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->translationKey));
        }

        return $this->redirectToRoute('messages_blocked');
    }

    #[Route('/engel-kaldir/{id}', name: 'unblock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unblock(Request $request, int $id): Response
    {
        $this->assertCsrf($request);
        $target = $this->users->resolve((string) $id);
        if (!$target instanceof User) {
            throw new NotFoundHttpException();
        }

        $this->blockService->unblock($this->member(), $target);
        $this->addFlash('success', $this->translator->trans('messages.flash.unblocked'));

        return $this->redirectToRoute('messages_blocked');
    }

    private function listThreads(Request $request, bool $archived): Response
    {
        $actor = $this->member();
        $search = trim((string) $request->query->get('q', ''));
        $result = $this->paginator->paginate(
            $this->threads->createInboxQueryBuilder($actor, $archived, $search !== '' ? $search : null),
            $request->query->getInt('page', 1),
            $this->config->threadsPerPage(),
        );

        return $this->render($this->templates->resolve('inbox'), [
            'messagesParentLayout' => $this->templates->layout(),
            'result' => $result,
            'archived' => $archived,
            'search' => $search,
            'unread' => $this->participants->unreadTotal($actor),
            'quota' => $this->quota->snapshot($actor),
            'heroTitle' => $this->config->heroTitle(),
            'heroDescription' => $this->config->heroDescription(),
            'csrfToken' => self::CSRF,
        ]);
    }

    private function requireThread(string $publicId, User $actor): MessageThread
    {
        $thread = $this->threads->findOneByPublicId($publicId);
        if (!$thread instanceof MessageThread || !$this->access->canViewThread($actor, $thread)) {
            throw new NotFoundHttpException($this->translator->trans('messages.error.not_found'));
        }

        return $thread;
    }

    private function member(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->config->enabled()) {
            throw $this->createAccessDeniedException($this->translator->trans('messages.error.disabled'));
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
