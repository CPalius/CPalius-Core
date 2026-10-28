<?php

declare(strict_types=1);

namespace App\Core\Notification\Twig;

use App\Core\Account\AccountIdentityChangeService;
use App\Core\Inbox\NotificationInboxPulseChannel;
use App\Core\Notification\NotificationInboxPresenter;
use App\Core\Notification\Repository\NotificationRepository;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

final class NotificationRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationInboxPresenter $presenter,
        private readonly Security $security,
        private readonly AccountIdentityChangeService $identityChanges,
    ) {
    }

    /**
     * Requests waiting for an administrator's decision, live (not "unread"): reading the notification
     * does not answer the request, so the bell keeps counting it until somebody approves or rejects.
     * 0 for anyone who may not decide them.
     */
    public function pendingIdentityCount(): int
    {
        if (!$this->security->isGranted('system.users.manage')) {
            return 0;
        }

        return $this->identityChanges->pendingCountCached();
    }

    /**
     * Everything the admin bell draws, in one call: the pending-request row, the other unread
     * notifications (identity alerts are the pending row, not repeated) and the badge number.
     *
     * @return array{badge: int, pending: int, items: list<array<string, mixed>>}
     */
    public function adminBell(int $limit = 6): array
    {
        $pending = $this->pendingIdentityCount();
        $items = [];
        foreach ($this->recent($limit + 4) as $item) {
            if (str_starts_with((string) $item['event_key'], 'account.identity.')) {
                continue;
            }
            $items[] = $item;
        }
        $items = \array_slice($items, 0, $limit);

        $user = $this->security->getUser();
        $unreadIdentity = $user instanceof User ? $this->notifications->countUnreadByEventPrefix($user, 'account.identity.') : 0;
        $unreadOther = max(0, $this->unreadCount() - $unreadIdentity);

        return ['badge' => $pending + $unreadOther, 'pending' => $pending, 'items' => $items];
    }

    public function unreadCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->notifications->countUnreadExceptEventPrefix(
            $user,
            NotificationInboxPulseChannel::EXCLUDE_PREFIX,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 6): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $out = [];
        foreach ($this->notifications->findUnreadForUserExceptEventPrefix(
            $user,
            NotificationInboxPulseChannel::EXCLUDE_PREFIX,
            $limit,
        ) as $row) {
            $out[] = $this->presenter->toArray($row);
        }

        return $out;
    }
}
