<?php

declare(strict_types=1);

namespace App\Core\Notification;

/**
 * One registered notification event type (core or module contribution).
 *
 * @phpstan-type ChannelList list<string>
 */
final readonly class NotificationTypeDefinition
{
    /**
     * @param ChannelList $defaultChannels
     */
    public function __construct(
        public string $eventKey,
        public string $labelKey,
        public string $module,
        public array $defaultChannels,
        public ?string $preferenceKey,
        public string $mailTemplate,
        public bool $allowSelf = false,
        public bool $exposeInAccount = true,
    ) {
    }
}
