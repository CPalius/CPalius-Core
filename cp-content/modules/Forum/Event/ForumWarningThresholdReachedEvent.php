<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumWarning;
use Symfony\Contracts\EventDispatcher\Event;

final class ForumWarningThresholdReachedEvent extends Event
{
    public const NAME = 'forum.warning.threshold_reached';

    public function __construct(
        private readonly ForumWarning $warning,
        private readonly User $user,
        private readonly int $activePoints,
        private readonly int $threshold,
        private readonly string $action,
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

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function getAction(): string
    {
        return $this->action;
    }
}
