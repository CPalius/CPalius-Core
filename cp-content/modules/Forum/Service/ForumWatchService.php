<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicWatch;
use Modules\Forum\Repository\ForumTopicWatchRepository;

final class ForumWatchService
{
    public function __construct(
        private readonly ForumTopicWatchRepository $watchRepository,
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
        return $this->watchRepository->findOneByTopicAndUser($topic, $user) !== null;
    }

    public function watch(ForumTopic $topic, User $user): void
    {
        if (!$this->isEnabled() || $this->isWatching($topic, $user)) {
            return;
        }

        $this->entityManager->persist(new ForumTopicWatch($topic, $user));
        $this->entityManager->flush();
    }

    public function unwatch(ForumTopic $topic, User $user): void
    {
        $row = $this->watchRepository->findOneByTopicAndUser($topic, $user);
        if ($row === null) {
            return;
        }

        $this->entityManager->remove($row);
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
        foreach ($this->watchRepository->findByTopic($topic) as $watch) {
            $users[] = $watch->getUser();
        }

        return $users;
    }
}
