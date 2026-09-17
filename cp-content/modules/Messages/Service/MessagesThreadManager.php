<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Core\Security\Flood\FloodService;
use App\Core\TextFormat\TextFormatAccess;
use App\Core\TextFormat\TextFormatProcessor;
use App\Core\TextFormat\TextFormatRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageParticipant;
use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Repository\MessageParticipantRepository;
use Modules\Messages\Repository\MessageThreadRepository;

/**
 * The only place a thread or a message is written.
 */
final class MessagesThreadManager
{
    public const FLOOD_EVENT = 'messages.send';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageThreadRepository $threads,
        private readonly MessageParticipantRepository $participantRepository,
        private readonly MessagesAccess $access,
        private readonly MessagesQuota $quota,
        private readonly MessagesConfig $config,
        private readonly MessagesNotificationService $notifications,
        private readonly TextFormatProcessor $textFormats,
        private readonly TextFormatAccess $textFormatAccess,
        private readonly FloodService $flood,
    ) {
    }

    public function startOrContinue(
        User $actor,
        User $recipient,
        string $body,
        string $requestedFormat,
        MessagesContext $context,
        ?string $subject = null,
    ): Message {
        $existing = $this->threads->findOneByPairKey(MessageThread::pairKey(
            (int) $actor->getId(),
            (int) $recipient->getId(),
            $context->type,
            $context->id,
        ));

        if ($existing instanceof MessageThread) {
            return $this->reply($existing, $actor, $body, $requestedFormat);
        }

        $denied = $this->access->canStartThread($actor, $recipient);
        if ($denied !== null) {
            throw new MessagesDeniedException($denied);
        }

        $this->assertFlood($actor);
        $body = $this->sanitizeBody($body, $requestedFormat);

        $thread = new MessageThread(
            MessageThread::pairKey((int) $actor->getId(), (int) $recipient->getId(), $context->type, $context->id),
            $actor,
            $subject,
        );
        $thread->setContext($context->type, $context->id, $context->label, $context->url);

        new MessageParticipant($thread, $actor);
        new MessageParticipant($thread, $recipient);

        $format = $this->textFormatAccess->resolve($requestedFormat, TextFormatRegistry::RESTRICTED);
        $message = new Message($thread, $actor, $body, $format);

        $actorState = $thread->participantFor($actor);
        $actorState?->markRead();

        $recipientState = $thread->participantFor($recipient);
        $recipientState?->incrementUnread();

        $this->entityManager->persist($thread);
        $this->entityManager->flush();

        $this->flood->register(self::FLOOD_EVENT, (string) $actor->getId(), $this->config->floodWindow());

        if ($recipientState !== null && !$recipientState->isMuted()) {
            $this->notifications->notifyNewMessage($message, $recipient);
        }

        return $message;
    }

    public function reply(MessageThread $thread, User $actor, string $body, string $requestedFormat): Message
    {
        if (!$this->access->canReply($actor, $thread)) {
            if ($thread->isClosed()) {
                throw new MessagesDeniedException('messages.error.closed');
            }

            throw new MessagesDeniedException('messages.error.cannot_reply');
        }

        if (!$this->quota->snapshot($actor)->canSend()) {
            throw new MessagesDeniedException('messages.error.quota');
        }

        $this->assertFlood($actor);
        $body = $this->sanitizeBody($body, $requestedFormat);
        $format = $this->textFormatAccess->resolve($requestedFormat, TextFormatRegistry::RESTRICTED);
        $message = new Message($thread, $actor, $body, $format);

        $actorState = $thread->participantFor($actor);
        $actorState?->markRead();
        $actorState?->restore();
        $actorState?->setArchived(false);

        $other = $thread->otherParticipant($actor);
        if ($other instanceof User) {
            $otherState = $thread->participantFor($other);
            $otherState?->incrementUnread();
        }

        $this->entityManager->flush();
        $this->flood->register(self::FLOOD_EVENT, (string) $actor->getId(), $this->config->floodWindow());

        if ($other instanceof User) {
            $otherState = $thread->participantFor($other);
            if ($otherState !== null && !$otherState->isMuted()) {
                $this->notifications->notifyNewMessage($message, $other);
            }
        }

        return $message;
    }

    public function markRead(MessageThread $thread, User $user): void
    {
        $participant = $thread->participantFor($user);
        if ($participant === null) {
            return;
        }

        $participant->markRead();
        $this->entityManager->flush();
    }

    /**
     * Clears every unread conversation for one member.
     *
     * Opening each thread one by one was the only way to get the red badge
     * down, which is not a reasonable ask of somebody who has been away for a
     * week. Hidden conversations are skipped: they are already out of the
     * member's inbox and do not contribute to the badge.
     *
     * @return int how many conversations were marked read
     */
    public function markAllRead(User $user): int
    {
        $cleared = 0;

        foreach ($this->participantRepository->findUnreadFor($user) as $participant) {
            $participant->markRead();
            ++$cleared;
        }

        if ($cleared > 0) {
            $this->entityManager->flush();
        }

        return $cleared;
    }

    public function archive(MessageThread $thread, User $user, bool $archived): void
    {
        $participant = $thread->participantFor($user);
        if ($participant === null) {
            throw new MessagesDeniedException('messages.error.not_participant');
        }

        $participant->setArchived($archived);
        $this->entityManager->flush();
    }

    public function mute(MessageThread $thread, User $user, bool $muted): void
    {
        $participant = $thread->participantFor($user);
        if ($participant === null) {
            throw new MessagesDeniedException('messages.error.not_participant');
        }

        $participant->setMuted($muted);
        $this->entityManager->flush();
    }

    public function hide(MessageThread $thread, User $user): void
    {
        $participant = $thread->participantFor($user);
        if ($participant === null) {
            throw new MessagesDeniedException('messages.error.not_participant');
        }

        $participant->hide();
        $this->entityManager->flush();
    }

    public function close(MessageThread $thread, User $moderator): void
    {
        if (!$this->access->canModerate()) {
            throw new MessagesDeniedException('messages.error.no_permission');
        }

        $thread->close($moderator);
        $this->entityManager->flush();
    }

    public function reopen(MessageThread $thread): void
    {
        if (!$this->access->canModerate()) {
            throw new MessagesDeniedException('messages.error.no_permission');
        }

        $thread->reopen();
        $this->entityManager->flush();
    }

    public function deleteMessage(Message $message, User $actor): void
    {
        $own = $message->getAuthor()?->getId() === $actor->getId();
        if (!$own && !$this->access->canModerate()) {
            throw new MessagesDeniedException('messages.error.no_permission');
        }

        if ($message->isDeleted()) {
            return;
        }

        $message->softDelete($actor);
        $this->entityManager->flush();
    }

    private function sanitizeBody(string $body, string $requestedFormat): string
    {
        $format = $this->textFormatAccess->resolve($requestedFormat, TextFormatRegistry::RESTRICTED);
        $clean = trim($this->textFormats->sanitizeForStorage($body, $format));

        if ($clean === '') {
            throw new MessagesDeniedException('messages.error.body_required');
        }

        $plain = trim(strip_tags($clean));
        if (mb_strlen($plain) > $this->config->maxBodyLength()) {
            throw new MessagesDeniedException('messages.error.body_too_long', [
                'max' => $this->config->maxBodyLength(),
            ]);
        }

        return $clean;
    }

    private function assertFlood(User $actor): void
    {
        $limit = $this->config->floodLimit();
        if ($limit <= 0) {
            return;
        }

        if (!$this->flood->isAllowed(self::FLOOD_EVENT, (string) $actor->getId(), $limit, $this->config->floodWindow())) {
            throw new MessagesDeniedException('messages.error.flood');
        }
    }
}
