<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\ForumPost;
use App\Entity\ForumPostReport;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mesaj raporlama / AACP moderasyon kuyruğu — Cotonti forums modülünde
 * doğrudan karşılığı olmayan, bu projeye özgü bir moderasyon eklentisi.
 */
final class ForumModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function reportPost(ForumPost $post, string $reason, ?User $reporter): ForumPostReport
    {
        $report = new ForumPostReport(
            $post,
            $reason,
            $reporter,
            $reporter?->getFullName(),
        );

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $report;
    }

    public function resolve(ForumPostReport $report, User $moderator): void
    {
        $report->resolve($moderator);
        $this->entityManager->flush();
    }

    public function dismiss(ForumPostReport $report, User $moderator): void
    {
        $report->dismiss($moderator);
        $this->entityManager->flush();
    }
}
