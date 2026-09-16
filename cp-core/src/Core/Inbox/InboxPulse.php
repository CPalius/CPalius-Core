<?php

declare(strict_types=1);

namespace App\Core\Inbox;

use App\Entity\User;

/**
 * Aggregates tagged inbox channels into one JSON payload for the theme poller.
 *
 * XenForo does the same with a short AJAX heartbeat: unread counts + latest id,
 * not websockets. The page already open picks up new alerts without a refresh.
 */
final class InboxPulse
{
    /**
     * @param iterable<InboxPulseChannelInterface> $channels
     */
    public function __construct(
        private readonly iterable $channels,
    ) {
    }

    /**
     * @return array{ok: true, channels: array<string, array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $channels = [];
        foreach ($this->channels as $channel) {
            try {
                $channels[$channel->name()] = $channel->pulse($user);
            } catch (\Throwable) {
                // A broken module channel must not take down the core bell.
            }
        }

        return [
            'ok' => true,
            'channels' => $channels,
        ];
    }
}
