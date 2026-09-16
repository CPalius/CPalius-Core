<?php

declare(strict_types=1);

namespace App\Core\Inbox;

use App\Entity\User;

/**
 * One live-inbox channel (core notifications, a messaging module, …).
 *
 * Core never imports a module. Modules tag this interface and appear in
 * GET /hesap/nabiz when they are active.
 */
interface InboxPulseChannelInterface
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function pulse(User $user): array;
}
