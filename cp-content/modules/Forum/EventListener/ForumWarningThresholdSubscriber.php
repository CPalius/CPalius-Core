<?php

declare(strict_types=1);

namespace Modules\Forum\EventListener;

use Modules\Forum\Entity\ForumBan;
use Modules\Forum\Event\ForumWarningThresholdReachedEvent;
use Modules\Forum\Service\ForumBanService;
use Modules\Forum\Service\ForumWarningService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ForumWarningThresholdSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ForumBanService $banService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ForumWarningThresholdReachedEvent::NAME => 'onThresholdReached',
        ];
    }

    public function onThresholdReached(ForumWarningThresholdReachedEvent $event): void
    {
        $user = $event->getUser();
        if ($this->banService->isBanned($user) || $this->banService->isMuted($user)) {
            return;
        }

        $type = $event->getAction() === ForumWarningService::ACTION_BAN
            ? ForumBan::TYPE_BAN
            : ForumBan::TYPE_MUTE;

        $reasonKey = $type === ForumBan::TYPE_BAN
            ? 'forum.warning.auto_ban_reason'
            : 'forum.warning.auto_mute_reason';

        $this->banService->ban($user, $type, $this->translator->trans($reasonKey), null, null);
    }
}
