<?php

declare(strict_types=1);

namespace Modules\Messages\Inbox;

use App\Core\Inbox\InboxPulseChannelInterface;
use App\Entity\User;
use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Repository\MessageParticipantRepository;
use Modules\Messages\Repository\MessageRepository;
use Modules\Messages\Repository\MessageThreadRepository;
use Modules\Messages\Service\MessagesConfig;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MessagesInboxPulseChannel implements InboxPulseChannelInterface
{
    public const NAME = 'messages';

    public function __construct(
        private readonly MessagesConfig $config,
        private readonly MessageParticipantRepository $participants,
        private readonly MessageRepository $messages,
        private readonly MessageThreadRepository $threads,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function pulse(User $user): array
    {
        if (!$this->config->enabled()) {
            return [
                'unread' => 0,
                'latest_id' => 0,
                'items' => [],
                'inbox_url' => '',
                'sound_url' => '',
            ];
        }

        $items = [];
        foreach ($this->threads->recentForPulse($user, 6) as $thread) {
            $items[] = $this->item($thread, $user);
        }

        return [
            'unread' => $this->participants->unreadTotal($user),
            'latest_id' => $this->messages->latestIncomingId($user),
            'items' => $items,
            'inbox_url' => $this->route('messages_inbox'),
            'sound_url' => $this->route('messages_notify_sound'),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     unread: bool,
     *     text: string,
     *     url: string,
     *     icon: string,
     *     event_key: string,
     *     created_at: string,
     *     created_label: string
     * }
     */
    private function item(MessageThread $thread, User $viewer): array
    {
        $me = $thread->participantFor($viewer);
        $peer = $thread->otherParticipant($viewer);
        $name = $peer?->getPublicDisplayName() ?: $this->translator->trans('messages.thread.unknown');
        $label = $thread->getSubject() ?: $thread->getContextLabel() ?: $this->translator->trans('messages.thread.no_subject');
        $unread = $me !== null && $me->getUnreadCount() > 0;

        return [
            'id' => $thread->getPublicId(),
            'unread' => $unread,
            'text' => $name.' — '.$label,
            'url' => $this->route('messages_thread', ['publicId' => $thread->getPublicId()]),
            'icon' => 'envelope',
            'event_key' => 'messages.thread',
            'created_at' => $thread->getLastMessageAt()->format(\DateTimeInterface::ATOM),
            'created_label' => $thread->getLastMessageAt()->format('d.m.Y H:i'),
            'badge' => $me?->getUnreadCount() ?? 0,
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function route(string $name, array $params = []): string
    {
        try {
            return $this->urlGenerator->generate($name, $params);
        } catch (RoutingException) {
            return '';
        }
    }
}
