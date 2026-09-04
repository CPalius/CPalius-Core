<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use Modules\Forum\Entity\ForumUserReputation;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

/** Dispatched after a reputation vote is recorded. */
final class ForumReputationGivenEvent extends Event
{
    public const NAME = 'forum.reputation.given';

    public function __construct(
        private readonly ForumUserReputation $reputation,
        private readonly User $fromUser,
        private readonly User $toUser,
    ) {
    }

    public function getReputation(): ForumUserReputation
    {
        return $this->reputation;
    }

    public function getFromUser(): User
    {
        return $this->fromUser;
    }

    public function getToUser(): User
    {
        return $this->toUser;
    }
}
