<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Entity\User;
use Modules\Messages\Repository\MessageRepository;
use Modules\Messages\Repository\MessageThreadRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Counts sent messages against the operator-configured caps.
 */
final class MessagesQuota
{
    public function __construct(
        private readonly MessagesConfig $config,
        private readonly MessageRepository $messages,
        private readonly MessageThreadRepository $threads,
        private readonly Security $security,
    ) {
    }

    public function snapshot(User $user): MessagesQuotaSnapshot
    {
        $now = new \DateTimeImmutable();
        $today = $now->setTime(0, 0, 0);

        return new MessagesQuotaSnapshot(
            hourlySent: $this->messages->countSentSince($user, $now->modify('-1 hour')),
            hourlyLimit: $this->config->hourlySendLimit(),
            dailySent: $this->messages->countSentSince($user, $today),
            dailyLimit: $this->config->dailySendLimit(),
            threadsStartedToday: $this->threads->countStartedSince($user, $today),
            threadLimit: $this->config->dailyNewThreadLimit(),
            exempt: $this->security->isGranted('messages.quota.exempt'),
        );
    }
}
