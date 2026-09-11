<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Modules\Forum\Entity\ForumPresence;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPresenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tracks who is currently browsing the forum and builds the stats-bar online list.
 */
final class ForumPresenceService
{
    public const ONLINE_WINDOW_SECONDS = 300;
    public const TOUCH_THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly ForumPresenceRepository $presenceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function touch(string $sessionHash, ?User $user, ?int $topicId = null): void
    {
        if ($sessionHash === '') {
            return;
        }

        try {
            $this->doTouch($sessionHash, $user, $topicId);
        } catch (\Throwable) {
            return;
        }
    }

    private function doTouch(string $sessionHash, ?User $user, ?int $topicId): void
    {
        $now = new \DateTimeImmutable();
        $presence = $this->presenceRepository->findOneBySessionHash($sessionHash);
        $topic = $this->resolveTopic($topicId);

        if ($presence === null) {
            $presence = new ForumPresence($sessionHash, $user);
            $presence->setTopic($topic);
            $this->entityManager->persist($presence);
            $this->entityManager->flush();

            return;
        }

        $userChanged = $presence->getUser()?->getId() !== $user?->getId();
        $topicChanged = $presence->getTopic()?->getId() !== $topicId;
        $elapsed = $now->getTimestamp() - $presence->getLastSeenAt()->getTimestamp();
        $stale = $elapsed >= self::TOUCH_THROTTLE_SECONDS;

        if (!$userChanged && !$topicChanged && !$stale) {
            return;
        }

        if ($userChanged) {
            $presence->setUser($user);
        }
        if ($topicChanged) {
            $presence->setTopic($topic);
        }
        $presence->touch();
        $this->entityManager->flush();
    }

    private function resolveTopic(?int $topicId): ?ForumTopic
    {
        if ($topicId === null || $topicId <= 0) {
            return null;
        }

        return $this->entityManager->getReference(ForumTopic::class, $topicId);
    }

    /**
     * @return array{
     *     members: list<array{id: int, name: string, slug: string, user: User}>,
     *     memberCount: int,
     *     guestCount: int
     * }
     */
    public function currentOnline(): array
    {
        try {
            return $this->fetchCurrentOnline();
        } catch (\Throwable) {
            return ['members' => [], 'memberCount' => 0, 'guestCount' => 0];
        }
    }

    /**
     * @return array{
     *     members: list<array{id: int, name: string, slug: string, user: User}>,
     *     memberCount: int,
     *     guestCount: int
     * }
     */
    private function fetchCurrentOnline(): array
    {
        $since = (new \DateTimeImmutable())->modify('-'.self::ONLINE_WINDOW_SECONDS.' seconds');
        $this->presenceRepository->purgeStale($since);

        $seen = [];
        $members = [];
        foreach ($this->presenceRepository->findActiveMembers($since) as $presence) {
            $user = $presence->getUser();
            if ($user === null) {
                continue;
            }
            $id = $user->getId();
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $members[] = [
                'id' => $id,
                'name' => $this->memberLabel($user),
                'slug' => $user->getProfileSlug(),
                'user' => $user,
            ];
        }

        usort($members, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'members' => $members,
            'memberCount' => \count($members),
            'guestCount' => $this->presenceRepository->countActiveGuests($since),
        ];
    }

    /**
     * Who is currently viewing this thread (online window only).
     *
     * @return array{
     *     members: list<array{id: int, name: string, slug: string, user: User}>,
     *     memberCount: int,
     *     guestCount: int
     * }
     */
    public function currentOnTopic(ForumTopic $topic, int $limit = 16): array
    {
        try {
            return $this->fetchCurrentOnTopic($topic, $limit);
        } catch (\Throwable) {
            return ['members' => [], 'memberCount' => 0, 'guestCount' => 0];
        }
    }

    /**
     * @return array{
     *     members: list<array{id: int, name: string, slug: string, user: User}>,
     *     memberCount: int,
     *     guestCount: int
     * }
     */
    private function fetchCurrentOnTopic(ForumTopic $topic, int $limit): array
    {
        $since = (new \DateTimeImmutable())->modify('-'.self::ONLINE_WINDOW_SECONDS.' seconds');
        $seen = [];
        $members = [];
        $guestCount = 0;

        foreach ($this->presenceRepository->findActiveOnTopic($topic, $since) as $presence) {
            $user = $presence->getUser();
            if ($user === null) {
                ++$guestCount;
                continue;
            }
            if (!$user->isActive()) {
                continue;
            }
            $id = $user->getId();
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $members[] = [
                'id' => $id,
                'name' => $this->memberLabel($user),
                'slug' => $user->getProfileSlug(),
                'user' => $user,
            ];
        }

        $memberCount = \count($members);

        return [
            'members' => \array_slice($members, 0, max(0, $limit)),
            'memberCount' => $memberCount,
            'guestCount' => $guestCount,
        ];
    }

    public function hashSessionId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function hashAnonymousVisitor(string $ip, string $userAgent): string
    {
        return hash('sha256', 'anon|'.$ip.'|'.$userAgent);
    }

    private function memberLabel(User $user): string
    {
        $label = $user->getPublicDisplayName();

        return $label !== '' ? $label : ('#'.(string) $user->getId());
    }
}
