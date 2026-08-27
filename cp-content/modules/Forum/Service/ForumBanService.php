<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\ForumBan;
use App\Entity\User;
use App\Repository\ForumBanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Forum-özel yasaklama (BAN: forumu göremez) ve susturma (MUTE: okuyabilir,
 * gönderemez). Kullanıcının site geneli hesabını/rollerini ETKİLEMEZ.
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

    public function ban(User $user, int $type, string $reason, ?User $moderator, ?\DateTimeImmutable $expiresAt): ForumBan
    {
        $ban = new ForumBan($user, $type, $reason, $moderator, $expiresAt);
        $this->entityManager->persist($ban);
        $this->entityManager->flush();

        return $ban;
    }

    public function revoke(ForumBan $ban): void
    {
        $ban->revoke();
        $this->entityManager->flush();
    }
}
