<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;

/**
 * Safe section delete: block if children exist; require title confirmation when topics/posts remain.
 */
final class ForumSectionDeletionService
{
    public function __construct(
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function analyze(ForumSection $section): ForumSectionDeletionImpact
    {
        $childTitles = $this->sectionRepository->findDirectChildTitles($section);
        $topicCount = $this->topicRepository->countBySection($section);
        $postCount = $topicCount > 0
            ? $this->postRepository->countBySection($section)
            : 0;

        return new ForumSectionDeletionImpact(
            childSectionCount: \count($childTitles),
            topicCount: $topicCount,
            postCount: $postCount,
            childSectionTitles: $childTitles,
        );
    }

    /**
     * Validate a delete request; returns a translation key or null when valid.
     */
    public function validateRequest(
        ForumSection $section,
        ForumSectionDeletionImpact $impact,
        bool $confirmed,
        string $confirmTitle,
    ): ?string {
        if (!$impact->isDeletionAllowed()) {
            return 'studio.forum.sections.delete.blocked_children';
        }

        if (!$confirmed) {
            return 'studio.forum.sections.delete.confirm_required';
        }

        if ($impact->requiresTitleConfirmation() && trim($confirmTitle) !== $section->getTitle()) {
            return 'studio.forum.sections.delete.title_mismatch';
        }

        return null;
    }

    public function delete(ForumSection $section): void
    {
        $this->entityManager->remove($section);
        $this->entityManager->flush();
    }
}
