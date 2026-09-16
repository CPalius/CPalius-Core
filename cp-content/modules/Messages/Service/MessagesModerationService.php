<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageReport;
use Modules\Messages\Entity\MessageRestriction;
use Modules\Messages\Repository\MessageReportRepository;
use Modules\Messages\Repository\MessageRestrictionRepository;

final class MessagesModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageReportRepository $reports,
        private readonly MessageRestrictionRepository $restrictions,
        private readonly MessagesAccess $access,
    ) {
    }

    public function report(Message $message, User $reporter, string $reason, ?string $details): MessageReport
    {
        if (!$this->access->canReport($reporter, $message)) {
            throw new MessagesDeniedException('messages.error.cannot_report');
        }

        $existing = $this->reports->findExisting($message, $reporter);
        if ($existing instanceof MessageReport) {
            throw new MessagesDeniedException('messages.error.already_reported');
        }

        $report = new MessageReport($message, $reporter, $reason, $details);
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $report;
    }

    public function resolve(MessageReport $report, User $moderator, string $note = ''): void
    {
        $this->assertReportModerator();
        $report->resolve($moderator, $note);
        $this->entityManager->flush();
    }

    public function dismiss(MessageReport $report, User $moderator, string $note = ''): void
    {
        $this->assertReportModerator();
        $report->dismiss($moderator, $note);
        $this->entityManager->flush();
    }

    public function restrict(User $target, User $moderator, string $reason, ?\DateTimeImmutable $expiresAt): MessageRestriction
    {
        $this->assertModerator();

        $existing = $this->restrictions->findForUser($target);
        if ($existing instanceof MessageRestriction) {
            $existing->replace($reason, $moderator, $expiresAt);
            $this->entityManager->flush();

            return $existing;
        }

        $restriction = new MessageRestriction($target, $reason, $moderator, $expiresAt);
        $this->entityManager->persist($restriction);
        $this->entityManager->flush();

        return $restriction;
    }

    public function liftRestriction(User $target): void
    {
        $this->assertModerator();

        $existing = $this->restrictions->findForUser($target);
        if ($existing === null || !$existing->isActive()) {
            return;
        }

        $existing->revoke();
        $this->entityManager->flush();
    }

    private function assertModerator(): void
    {
        if (!$this->access->canModerate()) {
            throw new MessagesDeniedException('messages.error.no_permission');
        }
    }

    private function assertReportModerator(): void
    {
        if (!$this->access->canModerate()) {
            throw new MessagesDeniedException('messages.error.no_permission');
        }
    }
}
