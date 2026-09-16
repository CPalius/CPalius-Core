<?php

declare(strict_types=1);

namespace App\Core\Inbox;

use App\Core\Notification\NotificationInboxPresenter;
use App\Core\Notification\Repository\NotificationRepository;
use App\Entity\User;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Core alerts (forum, workflow, …). Direct messages live on a module channel
 * so the bell and the envelope stay separate, the way XenForo splits them.
 */
final class NotificationInboxPulseChannel implements InboxPulseChannelInterface
{
    public const NAME = 'notifications';

    /** Direct-message pings stay on the messages badge, not the bell. */
    public const EXCLUDE_PREFIX = 'messages.';

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationInboxPresenter $presenter,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function pulse(User $user): array
    {
        $items = [];
        foreach ($this->notifications->findForUserExceptEventPrefix($user, self::EXCLUDE_PREFIX, 6) as $row) {
            $items[] = $this->presenter->toArray($row);
        }

        return [
            'unread' => $this->notifications->countUnreadExceptEventPrefix($user, self::EXCLUDE_PREFIX),
            'latest_id' => $this->notifications->latestIdExceptEventPrefix($user, self::EXCLUDE_PREFIX),
            'items' => $items,
            'inbox_url' => $this->route('account_notifications'),
            'mark_all_url' => $this->route('account_notifications_mark_all'),
        ];
    }

    private function route(string $name): string
    {
        try {
            return $this->urlGenerator->generate($name);
        } catch (RoutingException) {
            return '';
        }
    }
}
