<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Inbox;

use App\Core\Inbox\InboxPulse;
use App\Core\Inbox\InboxPulseChannelInterface;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InboxPulse::class)]
final class InboxPulseTest extends TestCase
{
    public function testAggregatesNamedChannels(): void
    {
        $channel = new class implements InboxPulseChannelInterface {
            public function name(): string
            {
                return 'notifications';
            }

            public function pulse(User $user): array
            {
                return ['unread' => 2, 'latest_id' => 9];
            }
        };

        $pulse = new InboxPulse([$channel]);
        $user = $this->createMock(User::class);

        self::assertSame([
            'ok' => true,
            'channels' => [
                'notifications' => ['unread' => 2, 'latest_id' => 9],
            ],
        ], $pulse->forUser($user));
    }

    public function testSkipsABrokenChannel(): void
    {
        $ok = new class implements InboxPulseChannelInterface {
            public function name(): string
            {
                return 'notifications';
            }

            public function pulse(User $user): array
            {
                return ['unread' => 1];
            }
        };
        $broken = new class implements InboxPulseChannelInterface {
            public function name(): string
            {
                return 'messages';
            }

            public function pulse(User $user): array
            {
                throw new \RuntimeException('missing table');
            }
        };

        $pulse = new InboxPulse([$ok, $broken]);
        $user = $this->createMock(User::class);

        self::assertSame([
            'ok' => true,
            'channels' => [
                'notifications' => ['unread' => 1],
            ],
        ], $pulse->forUser($user));
    }
}
