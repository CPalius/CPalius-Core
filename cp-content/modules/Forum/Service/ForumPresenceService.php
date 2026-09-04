<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Modules\Forum\Entity\ForumPresence;
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

    public function touch(string $sessionHash, ?User $user): void
    {
        if ($sessionHash === '') {
            return;
        }

        try {
            $this->doTouch($sessionHash, $user);
        } catch (\Throwable) {
            return;
        }
    }

    private function doTouch(string $sessionHash, ?User $user): void
    {
        $now = new \DateTimeImmutable();
        $presence = $this->presenceRepository->findOneBySessionHash($sessionHash);

        if ($presence === null) {
            $presence = new ForumPresence($sessionHash, $user);
            $this->entityManager->persist($presence);
            $this->entityManager->flush();

            return;
        }

        if ($user !== null && $presence->getUser()?->getId() !== $user->getId()) {
            $presence->setUser($user);
            $presence->touch();
            $this->entityManager->flush();

            return;
        }

        $elapsed = $now->getTimestamp() - $presence->getLastSeenAt()->getTimestamp();
        if ($elapsed < self::TOUCH_THROTTLE_SECONDS) {
            return;
        }

        $presence->touch();
        $this->entityManager->flush();
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

    public function hashSessionId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function memberLabel(User $user): string
    {
        $username = trim((string) ($user->getUsername() ?? ''));
        if ($username !== '') {
            return $username;
        }

        $fullName = trim($user->getFullName());
        if ($fullName !== '' && $fullName !== $user->getEmail()) {
            return $fullName;
        }

        return $username !== '' ? $username : $user->getEmail();
    }
}
