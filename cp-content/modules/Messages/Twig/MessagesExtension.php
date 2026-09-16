<?php

declare(strict_types=1);

namespace Modules\Messages\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MessagesExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_messages_unread', [MessagesRuntime::class, 'unread']),
            new TwigFunction('cp_messages_compose_url', [MessagesRuntime::class, 'composeUrl']),
            new TwigFunction('cp_messages_can_report', [MessagesRuntime::class, 'canReport']),
            new TwigFunction('messages_desk_tabs', [MessagesRuntime::class, 'deskTabs']),
        ];
    }
}
