<?php

declare(strict_types=1);

namespace App\Core\Notification\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NotificationExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_notification_unread_count', [NotificationRuntime::class, 'unreadCount']),
            new TwigFunction('cp_recent_notifications', [NotificationRuntime::class, 'recent']),
        ];
    }
}
