<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumUserStats;
use Modules\Forum\Entity\ForumWarning;
use Modules\Forum\Event\ForumWarningIssuedEvent;
use Modules\Forum\Event\ForumWarningThresholdReachedEvent;
use Modules\Forum\Repository\ForumUserStatsRepository;
use Modules\Forum\Repository\ForumWarningRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ForumWarningService
{
    public const ACTION_MUTE = 'mute';
    public const ACTION_BAN = 'ban';

    public function __construct(
        private readonly ForumWarningRepository $warningRepository,
        private readonly ForumUserStatsRepository $userStatsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function warn(
        User $user,
        string $reason,
        int $points = 1,
        ?User $warnedBy = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): ForumWarning {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('forum.error.warning_reason_required');
        }

        $warning = new ForumWarning($user, $reason, max(0, $points), $warnedBy);
        $warning->setExpiresAt($expiresAt);
        $this->entityManager->persist($warning);
        $this->entityManager->flush();

        $active = $this->syncUserPoints($user);
        $this->eventDispatcher->dispatch(
            new ForumWarningIssuedEvent($warning, $user, $active),
            ForumWarningIssuedEvent::NAME,
        );

        $threshold = $this->autoThreshold();
        if ($active >= $threshold && $threshold > 0) {
            $this->eventDispatcher->dispatch(
                new ForumWarningThresholdReachedEvent(
                    $warning,
                    $user,
                    $active,
                    $threshold,
                    $this->autoAction(),
                ),
                ForumWarningThresholdReachedEvent::NAME,
            );
        }

        return $warning;
    }

    /**
     * @return list<ForumWarning>
     */
    public function listFor(User $user): array
    {
        $this->syncUserPoints($user);

        return $this->warningRepository->findForUser($user);
    }

    public function revoke(ForumWarning $warning): void
    {
        $user = $warning->getUser();
        $this->entityManager->remove($warning);
        $this->entityManager->flush();
        $this->syncUserPoints($user);
    }

    public function activePoints(User $user): int
    {
        return $this->warningRepository->sumActivePoints($user, new \DateTimeImmutable());
    }

    public function syncUserPoints(User $user): int
    {
        $active = $this->activePoints($user);
        $stats = $this->userStatsRepository->findOneByUser($user);
        if (!$stats instanceof ForumUserStats) {
            $stats = new ForumUserStats($user);
            $this->entityManager->persist($stats);
        }
        $stats->setWarningPoints($active);
        $this->entityManager->flush();

        return $active;
    }

    public function autoThreshold(): int
    {
        return max(0, (int) $this->settingsRegistry->get('forum.warning_auto_threshold', 10));
    }

    public function autoAction(): string
    {
        $action = (string) $this->settingsRegistry->get('forum.warning_auto_action', self::ACTION_MUTE);

        return $action === self::ACTION_BAN ? self::ACTION_BAN : self::ACTION_MUTE;
    }
}
