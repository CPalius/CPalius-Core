<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumWarning;
use Symfony\Contracts\EventDispatcher\Event;

final class ForumWarningIssuedEvent extends Event
{
    public const NAME = 'forum.warning.issued';

    public function __construct(
        private readonly ForumWarning $warning,
        private readonly User $user,
        private readonly int $activePoints,
    ) {
    }

    public function getWarning(): ForumWarning
    {
        return $this->warning;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getActivePoints(): int
    {
        return $this->activePoints;
    }
}
