<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostReport;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Post reports and bulk topic moderation.
 */
final class ForumModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumTopicService $topicService,
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

    /**
     * @param list<ForumTopic> $topics
     */
    public function bulkLock(array $topics, bool $locked): void
    {
        foreach ($topics as $topic) {
            $topic->setLocked($locked);
            $topic->touch();
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<ForumTopic> $topics
     */
    public function bulkSticky(array $topics, bool $sticky): void
    {
        foreach ($topics as $topic) {
            $topic->setSticky($sticky);
            $topic->touch();
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<ForumTopic> $topics
     */
    public function bulkMove(array $topics, ForumSection $target, bool $keepRedirect = false): void
    {
        foreach ($topics as $topic) {
            if ($topic->getSection()->getId() === $target->getId()) {
                continue;
            }

            $this->topicService->moveTopic($topic, $target, $keepRedirect);
        }
    }

    /**
     * @param list<ForumTopic> $topics
     */
    public function bulkSoftDelete(array $topics): void
    {
        foreach ($topics as $topic) {
            if (!$topic->isDeleted()) {
                $this->topicService->deleteTopic($topic, false);
            }
        }
    }

    /**
     * @param list<ForumTopic> $topics
     */
    public function bulkRestore(array $topics): void
    {
        foreach ($topics as $topic) {
            if ($topic->isDeleted()) {
                $this->topicService->restoreTopic($topic);
            }
        }
    }

    /**
     * @param list<ForumTopic> $topics
     */
    public function bulkHardDelete(array $topics): void
    {
        foreach ($topics as $topic) {
            $this->topicService->deleteTopic($topic, true);
        }
    }

    public function approvePost(ForumPost $post): void
    {
        $this->topicService->publishHeldPost($post);
    }

    public function rejectHeldPost(ForumPost $post): void
    {
        $topic = $post->getTopic();
        $this->topicService->deletePost($post);
        if ($topic->isModerated() && $this->entityManager->contains($topic)) {
            $this->topicService->deleteTopic($topic, false);
        }
    }

    /**
     * @param list<ForumTopic> $sources
     */
    public function mergeInto(array $sources, ForumTopic $target): void
    {
        foreach ($sources as $source) {
            if ($source->getId() === $target->getId()) {
                continue;
            }

            $this->topicService->mergeTopics($source, $target);
        }
    }

    /**
     * @param list<ForumPost> $posts
     */
    public function bulkDeletePosts(array $posts): void
    {
        foreach ($posts as $post) {
            $this->topicService->deletePost($post);
        }
    }

    /**
     * @param list<ForumPost> $posts
     */
    public function mergePosts(array $posts, User $editor): ForumPost
    {
        return $this->topicService->mergePosts($posts, $editor);
    }
}
