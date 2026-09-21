<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumBoardStats;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumUserStats;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumBoardStatsRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Repository\ForumUserStatsRepository;

/**
 * Recount-only. Hot-path writes use ForumCounterService (+= / roll-up).
 * COUNT(*) is legal here and on `forum:recount` only.
 *
 * @deprecated use ForumCounterService on write paths
 */
final class ForumStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumUserStatsRepository $userStatsRepository,
        private readonly ForumBoardStatsRepository $boardStatsRepository,
    ) {
    }

    public function syncSection(ForumSection $section): void
    {
        $id = $section->getId();
        if ($id === null) {
            return;
        }

        $path = $section->getParentPath() !== '' ? $section->getParentPath() : '/'.$id.'/';
        $conn = $this->entityManager->getConnection();

        $topics = $conn->fetchAssociative(
            'SELECT
                COALESCE(SUM(t.discussion_state = :visible AND t.moved_to_topic_id IS NULL), 0) AS visible_n,
                COALESCE(SUM(t.discussion_state = :held AND t.moved_to_topic_id IS NULL), 0) AS held_n,
                COALESCE(SUM(t.discussion_state = :deleted AND t.moved_to_topic_id IS NULL), 0) AS deleted_n
             FROM cp_forum_topics t
             INNER JOIN cp_forum_sections s ON s.id = t.section_id
             WHERE s.id = :sid OR s.parent_path LIKE :like',
            [
                'visible' => ForumDiscussionState::Visible->value,
                'held' => ForumDiscussionState::Moderated->value,
                'deleted' => ForumDiscussionState::Deleted->value,
                'sid' => $id,
                'like' => $path.'%',
            ],
        ) ?: [];

        $posts = $conn->fetchAssociative(
            'SELECT
                COALESCE(SUM(p.discussion_state = :visible AND t.discussion_state = :visible AND t.moved_to_topic_id IS NULL), 0) AS visible_n,
                COALESCE(SUM(p.discussion_state = :held AND t.discussion_state <> :deleted AND t.moved_to_topic_id IS NULL), 0) AS held_n,
                COALESCE(SUM(p.discussion_state = :deleted OR (t.discussion_state = :deleted AND t.moved_to_topic_id IS NULL)), 0) AS deleted_n
             FROM cp_forum_posts p
             INNER JOIN cp_forum_topics t ON t.id = p.topic_id
             INNER JOIN cp_forum_sections s ON s.id = p.section_id
             WHERE s.id = :sid OR s.parent_path LIKE :like',
            [
                'visible' => ForumDiscussionState::Visible->value,
                'held' => ForumDiscussionState::Moderated->value,
                'deleted' => ForumDiscussionState::Deleted->value,
                'sid' => $id,
                'like' => $path.'%',
            ],
        ) ?: [];

        $section->setTopicCount((int) ($topics['visible_n'] ?? 0));
        $section->setTopicCountHeld((int) ($topics['held_n'] ?? 0));
        $section->setTopicCountDeleted((int) ($topics['deleted_n'] ?? 0));
        $section->setPostCount((int) ($posts['visible_n'] ?? 0));
        $section->setPostCountHeld((int) ($posts['held_n'] ?? 0));
        $section->setPostCountDeleted((int) ($posts['deleted_n'] ?? 0));

        $lastPostId = $conn->fetchOne(
            'SELECT p.id
             FROM cp_forum_posts p
             INNER JOIN cp_forum_topics t ON t.id = p.topic_id
             INNER JOIN cp_forum_sections s ON s.id = p.section_id
             WHERE p.discussion_state = :visible
               AND t.discussion_state = :visible
               AND t.moved_to_topic_id IS NULL
               AND (s.id = :sid OR s.parent_path LIKE :like)
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 1',
            [
                'visible' => ForumDiscussionState::Visible->value,
                'sid' => $id,
                'like' => $path.'%',
            ],
        );

        $lastPost = $lastPostId !== false && $lastPostId !== null
            ? $this->entityManager->find(ForumPost::class, (int) $lastPostId)
            : null;

        if ($lastPost instanceof ForumPost) {
            $topic = $lastPost->getTopic();
            $section->setLastTopicId($topic->getId());
            $section->setLastTopicTitle($topic->getTitle());
            $section->setLastPostId($lastPost->getId());
            $section->setLastPostAt($lastPost->getCreatedAt());
            $section->setLastPoster($lastPost->getAuthor());
            $section->setLastPosterName($lastPost->getPosterName());
        } else {
            $section->setLastTopicId(null);
            $section->setLastTopicTitle(null);
            $section->setLastPostId(null);
            $section->setLastPostAt(null);
            $section->setLastPoster(null);
            $section->setLastPosterName(null);
        }

        $section->touch();
        $this->entityManager->flush();
    }

    public function syncTopic(ForumTopic $topic): void
    {
        $buckets = ['visible' => 0, 'moderated' => 0, 'deleted' => 0];
        foreach ($this->postRepository->countBucketsByTopic($topic) as $state => $count) {
            $buckets[$state] = $count;
        }

        $topic->setPostCount($buckets['visible']);
        $topic->setPostCountHeld($buckets['moderated']);
        $topic->setPostCountDeleted($buckets['deleted']);

        $lastPost = $this->postRepository->findLastVisibleByTopic($topic);
        if ($lastPost instanceof ForumPost) {
            $topic->setLastPoster($lastPost->getAuthor());
            $topic->setLastPosterName($lastPost->getPosterName());
            $topic->setLastPostId($lastPost->getId());
            $topic->setLastPostDate($lastPost->getCreatedAt());
        }

        $firstPost = $this->postRepository->findFirstByTopic($topic);
        if ($firstPost instanceof ForumPost) {
            $topic->setFirstPostId($firstPost->getId());
            $topic->setPreview(mb_substr(strip_tags($firstPost->getBody()), 0, 128));
        }

        $topic->touch();
        $this->entityManager->flush();
        $this->syncSection($topic->getSection());
    }

    public function recountAll(?ForumSection $only = null): int
    {
        if ($only instanceof ForumSection) {
            foreach ($this->topicRepository->findBy(['section' => $only]) as $topic) {
                $this->syncTopic($topic);
            }
            $this->syncSection($only);

            return 1;
        }

        $topics = $this->topicRepository->findAll();
        foreach ($topics as $topic) {
            $this->syncTopic($topic);
        }

        $sections = $this->entityManager->getRepository(ForumSection::class)->findAll();
        foreach ($sections as $section) {
            $this->syncSection($section);
        }

        $this->recountUserAndBoardStats();

        return \count($sections);
    }

    private function recountUserAndBoardStats(): void
    {
        $conn = $this->entityManager->getConnection();

        $userRows = $conn->fetchAllAssociative(
            'SELECT u.id AS user_id,
                    COALESCE(p.visible_posts, 0) AS post_count,
                    COALESCE(t.visible_topics, 0) AS topic_count,
                    p.last_posted_at
             FROM cp_users u
             LEFT JOIN (
                SELECT p.author_id AS user_id,
                       SUM(p.discussion_state = :visible AND t.discussion_state = :visible AND t.moved_to_topic_id IS NULL) AS visible_posts,
                       MAX(CASE WHEN p.discussion_state = :visible AND t.discussion_state = :visible THEN p.created_at END) AS last_posted_at
                FROM cp_forum_posts p
                INNER JOIN cp_forum_topics t ON t.id = p.topic_id
                WHERE p.author_id IS NOT NULL
                GROUP BY p.author_id
             ) p ON p.user_id = u.id
             LEFT JOIN (
                SELECT first_poster_id AS user_id,
                       SUM(discussion_state = :visible AND moved_to_topic_id IS NULL) AS visible_topics
                FROM cp_forum_topics
                WHERE first_poster_id IS NOT NULL
                GROUP BY first_poster_id
             ) t ON t.user_id = u.id
             WHERE p.user_id IS NOT NULL OR t.user_id IS NOT NULL',
            ['visible' => ForumDiscussionState::Visible->value],
        );

        foreach ($userRows as $row) {
            $user = $this->entityManager->find(User::class, (int) $row['user_id']);
            if (!$user instanceof User) {
                continue;
            }
            $stats = $this->userStatsRepository->findOneByUser($user);
            if (!$stats instanceof ForumUserStats) {
                $stats = new ForumUserStats($user);
                $this->entityManager->persist($stats);
            }
            $stats->setPostCount((int) $row['post_count']);
            $stats->setTopicCount((int) $row['topic_count']);
            $stats->setLastPostedAt(
                $row['last_posted_at'] !== null ? new \DateTimeImmutable((string) $row['last_posted_at']) : null,
            );
        }

        $boardRows = $conn->fetchAllAssociative(
            'SELECT t.locale,
                    COALESCE(SUM(t.discussion_state = :visible AND t.moved_to_topic_id IS NULL), 0) AS topic_count,
                    COALESCE(SUM(t.discussion_state = :held AND t.moved_to_topic_id IS NULL), 0) AS topic_count_held
             FROM cp_forum_topics t
             GROUP BY t.locale',
            [
                'visible' => ForumDiscussionState::Visible->value,
                'held' => ForumDiscussionState::Moderated->value,
            ],
        );

        $postRows = $conn->fetchAllAssociative(
            'SELECT t.locale,
                    COALESCE(SUM(p.discussion_state = :visible AND t.discussion_state = :visible AND t.moved_to_topic_id IS NULL), 0) AS post_count,
                    COALESCE(SUM(p.discussion_state = :held AND t.discussion_state <> :deleted AND t.moved_to_topic_id IS NULL), 0) AS post_count_held,
                    MAX(CASE WHEN p.discussion_state = :visible AND t.discussion_state = :visible THEN p.id END) AS last_post_id
             FROM cp_forum_posts p
             INNER JOIN cp_forum_topics t ON t.id = p.topic_id
             GROUP BY t.locale',
            [
                'visible' => ForumDiscussionState::Visible->value,
                'held' => ForumDiscussionState::Moderated->value,
                'deleted' => ForumDiscussionState::Deleted->value,
            ],
        );
        $postsByLocale = [];
        foreach ($postRows as $row) {
            $postsByLocale[$row['locale']] = $row;
        }

        foreach ($boardRows as $row) {
            $locale = (string) $row['locale'];
            $stats = $this->boardStatsRepository->findOneByLocale($locale) ?? new ForumBoardStats($locale);
            if (!$this->entityManager->contains($stats)) {
                $this->entityManager->persist($stats);
            }
            $stats->setTopicCount((int) $row['topic_count']);
            $stats->setTopicCountHeld((int) $row['topic_count_held']);
            $post = $postsByLocale[$locale] ?? [];
            $stats->setPostCount((int) ($post['post_count'] ?? 0));
            $stats->setPostCountHeld((int) ($post['post_count_held'] ?? 0));
            $lastPostId = $post['last_post_id'] ?? null;
            if ($lastPostId !== null) {
                $last = $this->entityManager->find(ForumPost::class, (int) $lastPostId);
                if ($last instanceof ForumPost) {
                    $stats->setLastTopicId($last->getTopic()->getId());
                    $stats->setLastPostId($last->getId());
                    $stats->setLastPostAt($last->getCreatedAt());
                    $stats->setLastPoster($last->getAuthor());
                    $stats->setLastPosterName($last->getPosterName());
                }
            }
        }

        $this->entityManager->flush();
    }
}
