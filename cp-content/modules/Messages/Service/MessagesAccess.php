<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Entity\User;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Repository\MessageBlockRepository;
use Modules\Messages\Repository\MessageParticipantRepository;
use Modules\Messages\Repository\MessageRestrictionRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Single door for "may this user do this". Controllers never compare user ids
 * themselves, and they never inspect another module's entities.
 */
final class MessagesAccess
{
    public const DATA_ALLOW_FROM = 'messages_allow_from';

    public function __construct(
        private readonly MessagesConfig $config,
        private readonly MessagesQuota $quota,
        private readonly MessageBlockRepository $blocks,
        private readonly MessageRestrictionRepository $restrictions,
        private readonly MessageParticipantRepository $participants,
        private readonly Security $security,
    ) {
    }

    public function moduleEnabled(): bool
    {
        return $this->config->enabled();
    }

    public function canSend(User $user): bool
    {
        return $this->config->enabled()
            && $this->security->isGranted('messages.send')
            && $user->isActive()
            && !$this->isRestricted($user);
    }

    public function canModerate(): bool
    {
        return $this->security->isGranted('messages.moderate');
    }

    public function canViewThread(User $user, MessageThread $thread): bool
    {
        if ($this->canModerate()) {
            return true;
        }

        $participant = $thread->participantFor($user);

        return $participant !== null && !$participant->isHidden();
    }

    public function canReply(User $user, MessageThread $thread): bool
    {
        if (!$this->canSend($user) || $thread->isClosed()) {
            return false;
        }

        if (!$thread->hasParticipant($user)) {
            return $this->canModerate();
        }

        $other = $thread->otherParticipant($user);
        if ($other instanceof User && $this->blocks->isBlockedEitherWay($user, $other)) {
            return false;
        }

        return $this->quota->snapshot($user)->canSend();
    }

    public function canStartThread(User $actor, User $recipient): ?string
    {
        if (!$this->config->enabled()) {
            return 'messages.error.disabled';
        }

        if (!$this->config->allowNewThreads()) {
            return 'messages.error.new_threads_closed';
        }

        if (!$this->security->isGranted('messages.send')) {
            return 'messages.error.no_permission';
        }

        if (!$actor->isActive()) {
            return 'messages.error.account_inactive';
        }

        if ($this->isRestricted($actor)) {
            return 'messages.error.restricted';
        }

        if ($actor->getId() === $recipient->getId()) {
            return 'messages.error.self';
        }

        if (!$recipient->isActive()) {
            return 'messages.error.recipient_unavailable';
        }

        if ($this->blocks->isBlockedEitherWay($actor, $recipient)) {
            return 'messages.error.blocked';
        }

        $ageHours = $this->config->minAccountAgeHours();
        if ($ageHours > 0) {
            $eligibleAt = $actor->getCreatedAt()->modify('+'.$ageHours.' hours');
            if ($eligibleAt > new \DateTimeImmutable()) {
                return 'messages.error.too_new';
            }
        }

        $quota = $this->quota->snapshot($actor);
        if (!$quota->canStartThread()) {
            return 'messages.error.quota';
        }

        $privacy = $this->allowFrom($recipient);
        if ($privacy === MessagesConfig::PRIVACY_NOBODY && !$this->canModerate()) {
            return 'messages.error.privacy';
        }

        if ($privacy === MessagesConfig::PRIVACY_CONTACTS && !$this->canModerate()
            && !$this->participants->sharesThread($actor, $recipient)
        ) {
            return 'messages.error.privacy';
        }

        return null;
    }

    public function canReport(User $user, Message $message): bool
    {
        if (!$this->security->isGranted('messages.report') || $message->isDeleted()) {
            return false;
        }

        if ($message->getAuthor()?->getId() === $user->getId()) {
            return false;
        }

        return $this->canViewThread($user, $message->getThread());
    }

    public function canBlock(): bool
    {
        return $this->security->isGranted('messages.block');
    }

    public function isRestricted(User $user): bool
    {
        $row = $this->restrictions->findForUser($user);

        return $row !== null && $row->isActive();
    }

    public function allowFrom(User $user): string
    {
        $value = $user->getDataValue(self::DATA_ALLOW_FROM, $this->config->defaultAllowFrom());

        return \is_string($value) && \in_array($value, MessagesConfig::PRIVACY_OPTIONS, true)
            ? $value
            : $this->config->defaultAllowFrom();
    }
}
