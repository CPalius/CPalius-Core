<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Modules\Forum\Repository\ForumPostRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ForumPostHoldService
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly ForumPostRepository $postRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.post_moderation_enabled', false);
    }

    public function shouldHold(?User $user): bool
    {
        if (!$this->isEnabled() || $user === null) {
            return false;
        }

        if ($this->authorizationChecker->isGranted('forum.topic.moderate')) {
            return false;
        }

        $min = max(0, (int) $this->settingsRegistry->get('forum.post_moderation_min_posts', 5));

        return $this->postRepository->countByAuthor($user) < $min;
    }
}
