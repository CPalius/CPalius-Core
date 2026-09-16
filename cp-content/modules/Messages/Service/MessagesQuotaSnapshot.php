<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

/**
 * Snapshot of how many messages a member has sent versus the configured caps.
 */
final readonly class MessagesQuotaSnapshot
{
    public function __construct(
        public int $hourlySent,
        public int $hourlyLimit,
        public int $dailySent,
        public int $dailyLimit,
        public int $threadsStartedToday,
        public int $threadLimit,
        public bool $exempt,
    ) {
    }

    public function hourlyRemaining(): ?int
    {
        return $this->remaining($this->hourlySent, $this->hourlyLimit);
    }

    public function dailyRemaining(): ?int
    {
        return $this->remaining($this->dailySent, $this->dailyLimit);
    }

    public function threadsRemaining(): ?int
    {
        return $this->remaining($this->threadsStartedToday, $this->threadLimit);
    }

    public function canSend(): bool
    {
        if ($this->exempt) {
            return true;
        }

        return $this->fits($this->hourlySent, $this->hourlyLimit)
            && $this->fits($this->dailySent, $this->dailyLimit);
    }

    public function canStartThread(): bool
    {
        if ($this->exempt) {
            return true;
        }

        return $this->canSend() && $this->fits($this->threadsStartedToday, $this->threadLimit);
    }

    private function fits(int $used, int $limit): bool
    {
        return $limit <= 0 || $used < $limit;
    }

    private function remaining(int $used, int $limit): ?int
    {
        if ($this->exempt || $limit <= 0) {
            return null;
        }

        return max(0, $limit - $used);
    }
}
