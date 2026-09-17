<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumPresence;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Install\ForumPresenceKindSchema;
use Modules\Forum\Repository\ForumPresenceRepository;

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
        private readonly ForumPresenceKindSchema $kindSchema,
    ) {
    }

    /**
     * @param string $kind what the visitor is (see ForumVisitorKind); an unknown
     *                     value is stored as a guest rather than rejected, so a
     *                     caller that has not been updated cannot break presence
     */
    public function touch(
        string $sessionHash,
        ?User $user,
        ?int $topicId = null,
        string $kind = ForumVisitorKind::GUEST,
    ): void {
        if ($sessionHash === '') {
            return;
        }

        if (!ForumVisitorKind::isKnown($kind)) {
            $kind = ForumVisitorKind::GUEST;
        }

        try {
            $this->doTouch($sessionHash, $user, $topicId, $kind);
        } catch (\Throwable) {
            // The kind column arrives with this release and may be missing on a
            // site that took the files before the schema guard ran. Create it
            // and let the next request record presence normally.
            $this->kindSchema->ensure();

            return;
        }
    }

    private function doTouch(string $sessionHash, ?User $user, ?int $topicId, string $kind): void
    {
        $now = new \DateTimeImmutable();
        $presence = $this->presenceRepository->findOneBySessionHash($sessionHash);
        $topic = $this->resolveTopic($topicId);

        if ($presence === null) {
            $presence = new ForumPresence($sessionHash, $user, $kind);
            $presence->setTopic($topic);
            $this->entityManager->persist($presence);
            $this->entityManager->flush();

            return;
        }

        $userChanged = $presence->getUser()?->getId() !== $user?->getId();
        $topicChanged = $presence->getTopic()?->getId() !== $topicId;
        $kindChanged = $presence->getKind() !== $kind;
        $elapsed = $now->getTimestamp() - $presence->getLastSeenAt()->getTimestamp();
        $stale = $elapsed >= self::TOUCH_THROTTLE_SECONDS;

        if (!$userChanged && !$topicChanged && !$kindChanged && !$stale) {
            return;
        }

        if ($userChanged) {
            $presence->setUser($user);
        }
        if ($topicChanged) {
            $presence->setTopic($topic);
        }
        if ($kindChanged) {
            $presence->setKind($kind);
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
            $this->kindSchema->ensure();

            return [
                'members' => [],
                'memberCount' => 0,
                'guestCount' => 0,
                'spiderCount' => 0,
                'botCount' => 0,
                'total' => 0,
            ];
        }
    }

    /**
     * @return array{
     *     members: list<array{id: int, name: string, slug: string, user: User}>,
     *     memberCount: int,
     *     guestCount: int,
     *     spiderCount: int,
     *     botCount: int,
     *     total: int
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

        $byKind = $this->presenceRepository->countActiveByKind($since);
        $memberCount = \count($members);
        $guestCount = $byKind[ForumVisitorKind::GUEST] ?? 0;
        $spiderCount = $byKind[ForumVisitorKind::SPIDER] ?? 0;
        $botCount = $byKind[ForumVisitorKind::BOT] ?? 0;

        return [
            'members' => $members,
            'memberCount' => $memberCount,
            'guestCount' => $guestCount,
            'spiderCount' => $spiderCount,
            'botCount' => $botCount,
            'total' => $memberCount + $guestCount + $spiderCount + $botCount,
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
