<?php

declare(strict_types=1);

namespace App\Core\Notification\Twig;

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
    ) {
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
        foreach ($this->notifications->findForUserExceptEventPrefix(
            $user,
            NotificationInboxPulseChannel::EXCLUDE_PREFIX,
            $limit,
        ) as $row) {
            $out[] = $this->presenter->toArray($row);
        }

        return $out;
    }
}
