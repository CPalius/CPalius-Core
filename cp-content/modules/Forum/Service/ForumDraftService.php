<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumDraft;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumDraftRepository;

final class ForumDraftService
{
    public function __construct(
        private readonly ForumDraftRepository $draftRepository,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.drafts_enabled', true);
    }

    public function saveReply(User $user, ForumTopic $topic, string $body): ForumDraft
    {
        $draft = $this->draftRepository->findReplyDraft($user, $topic) ?? (new ForumDraft($user))->setTopic($topic);
        $draft->setBody($body);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    public function saveNewTopic(User $user, ForumSection $section, string $title, string $body): ForumDraft
    {
        $draft = $this->draftRepository->findNewTopicDraft($user, $section) ?? (new ForumDraft($user))->setSection($section);
        $draft->setTitle($title !== '' ? $title : null);
        $draft->setBody($body);
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    public function replyDraft(User $user, ForumTopic $topic): ?ForumDraft
    {
        return $this->draftRepository->findReplyDraft($user, $topic);
    }

    public function newTopicDraft(User $user, ForumSection $section): ?ForumDraft
    {
        return $this->draftRepository->findNewTopicDraft($user, $section);
    }

    public function discard(ForumDraft $draft): void
    {
        $this->entityManager->remove($draft);
        $this->entityManager->flush();
    }

    public function discardReply(User $user, ForumTopic $topic): void
    {
        $draft = $this->draftRepository->findReplyDraft($user, $topic);
        if ($draft !== null) {
            $this->discard($draft);
        }
    }

    public function discardNewTopic(User $user, ForumSection $section): void
    {
        $draft = $this->draftRepository->findNewTopicDraft($user, $section);
        if ($draft !== null) {
            $this->discard($draft);
        }
    }

    /** @return list<ForumDraft> */
    public function listForUser(User $user): array
    {
        return $this->draftRepository->findRecentForUser($user);
    }
}
