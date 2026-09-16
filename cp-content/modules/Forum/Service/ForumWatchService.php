<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumTopicUserStateRepository;

final class ForumWatchService
{
    public function __construct(
        private readonly ForumTopicUserStateRepository $stateRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.watch_enabled', true);
    }

    public function isWatching(ForumTopic $topic, User $user): bool
    {
        return $this->stateRepository->findOneByTopicAndUser($topic, $user)?->isWatching() === true;
    }

    public function watch(ForumTopic $topic, User $user): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $state = $this->stateRepository->findOrCreate($topic, $user);
        if ($state->isWatching()) {
            return;
        }

        $state->startWatching();
        $this->entityManager->flush();
    }

    /**
     * Clears the watch but keeps the row: the member's read position lives in
     * the same record now, and unwatching a thread should not mark it unread.
     */
    public function unwatch(ForumTopic $topic, User $user): void
    {
        $state = $this->stateRepository->findOneByTopicAndUser($topic, $user);
        if ($state === null || !$state->isWatching()) {
            return;
        }

        $state->stopWatching();

        if ($state->isEmpty()) {
            $this->entityManager->remove($state);
        }

        $this->entityManager->flush();
    }

    public function toggle(ForumTopic $topic, User $user): bool
    {
        if ($this->isWatching($topic, $user)) {
            $this->unwatch($topic, $user);

            return false;
        }

        $this->watch($topic, $user);

        return true;
    }

    /**
     * @return list<User>
     */
    public function watchers(ForumTopic $topic): array
    {
        $users = [];
        foreach ($this->stateRepository->findWatchersByTopic($topic) as $state) {
            $users[] = $state->getUser();
        }

        return $users;
    }
}
