<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumBan;
use App\Entity\User;
use Modules\Forum\Repository\ForumBanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Forum-only BAN (cannot see the board) and MUTE (read-only). Does not change site-wide account or roles.
 */
final class ForumBanService
{
    public function __construct(
        private readonly ForumBanRepository $banRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function activeBanFor(User $user): ?ForumBan
    {
        foreach ($this->banRepository->findActiveForUser($user) as $ban) {
            if ($ban->isBan()) {
                return $ban;
            }
        }

        return null;
    }

    public function activeMuteFor(User $user): ?ForumBan
    {
        foreach ($this->banRepository->findActiveForUser($user) as $ban) {
            if ($ban->isMute()) {
                return $ban;
            }
        }

        return null;
    }

    public function isBanned(User $user): bool
    {
        return $this->activeBanFor($user) !== null;
    }

    public function isMuted(User $user): bool
    {
        return $this->activeMuteFor($user) !== null;
    }

    /**
     * Ban/mute lookup for a member list without per-user queries.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{ban: ?ForumBan, mute: ?ForumBan}>
     */
    public function activeRestrictionsByUserIds(array $userIds): array
    {
        $map = [];
        foreach ($userIds as $userId) {
            $map[$userId] = ['ban' => null, 'mute' => null];
        }

        foreach ($this->banRepository->findActiveForUserIds($userIds) as $ban) {
            $userId = $ban->getUser()?->getId();
            if ($userId === null || !isset($map[$userId])) {
                continue;
            }

            if ($ban->isBan() && $map[$userId]['ban'] === null) {
                $map[$userId]['ban'] = $ban;
            }
            if ($ban->isMute() && $map[$userId]['mute'] === null) {
                $map[$userId]['mute'] = $ban;
            }
        }

        return $map;
    }

    public function ban(User $user, int $type, string $reason, ?User $moderator, ?\DateTimeImmutable $expiresAt): ForumBan
    {
        return $this->banTarget($user, $type, $reason, $moderator, $expiresAt);
    }

    public function banTarget(
        ?User $user,
        int $type,
        string $reason,
        ?User $moderator,
        ?\DateTimeImmutable $expiresAt,
        ?string $ipAddress = null,
        ?string $email = null,
    ): ForumBan {
        $ban = new ForumBan($user, $type, $reason, $moderator, $expiresAt, $ipAddress, $email);
        $this->entityManager->persist($ban);
        $this->entityManager->flush();

        return $ban;
    }

    public function activeBanForIp(?string $ip): ?ForumBan
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        foreach ($this->banRepository->findActiveForIp($ip) as $ban) {
            if ($ban->isBan()) {
                return $ban;
            }
        }

        return null;
    }

    public function activeBanForEmail(?string $email): ?ForumBan
    {
        if ($email === null || $email === '') {
            return null;
        }

        foreach ($this->banRepository->findActiveForEmail($email) as $ban) {
            if ($ban->isBan()) {
                return $ban;
            }
        }

        return null;
    }

    public function revoke(ForumBan $ban): void
    {
        $ban->revoke();
        $this->entityManager->flush();
    }
}
