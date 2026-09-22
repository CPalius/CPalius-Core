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

    public function syncTopic(ForumTopic $topic, bool $cascadeSection = true, bool $flush = true): void
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
        } else {
            $topic->setLastPoster(null);
            $topic->setLastPosterName(null);
            $topic->setLastPostId(null);
            $topic->setLastPostDate(null);
        }

        $firstPost = $this->postRepository->findFirstByTopic($topic);
        if ($firstPost instanceof ForumPost) {
            $topic->setFirstPostId($firstPost->getId());
            $topic->setPreview(mb_substr(strip_tags($firstPost->getBody()), 0, 128));
        }

        $topic->touch();
        if ($flush) {
            $this->entityManager->flush();
        }

        if ($cascadeSection) {
            $this->syncSection($topic->getSection());
        }
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

    /**
     * One member. COUNT(*) is the point — this is the repair tool.
     */
    public function recountUserById(int $userId, bool $flush = true): bool
    {
        $user = $this->entityManager->find(User::class, $userId);
        if (!$user instanceof User) {
            return false;
        }

        $conn = $this->entityManager->getConnection();
        $visible = ForumDiscussionState::Visible->value;
        $row = $conn->fetchAssociative(
            'SELECT
                (SELECT COUNT(*)
                 FROM cp_forum_posts p
                 INNER JOIN cp_forum_topics t ON t.id = p.topic_id
                 WHERE p.author_id = :uid
                   AND p.discussion_state = :visible
                   AND t.discussion_state = :visible
                   AND t.moved_to_topic_id IS NULL) AS post_count,
                (SELECT COUNT(*)
                 FROM cp_forum_topics t
                 WHERE t.first_poster_id = :uid
                   AND t.discussion_state = :visible
                   AND t.moved_to_topic_id IS NULL) AS topic_count,
                (SELECT MAX(p.created_at)
                 FROM cp_forum_posts p
                 INNER JOIN cp_forum_topics t ON t.id = p.topic_id
                 WHERE p.author_id = :uid
                   AND p.discussion_state = :visible
                   AND t.discussion_state = :visible) AS last_posted_at',
            ['uid' => $userId, 'visible' => $visible],
        ) ?: [];

        $stats = $this->userStatsRepository->findOneByUser($user);
        if (!$stats instanceof ForumUserStats) {
            $stats = new ForumUserStats($user);
            $this->entityManager->persist($stats);
        }

        $stats->setPostCount((int) ($row['post_count'] ?? 0));
        $stats->setTopicCount((int) ($row['topic_count'] ?? 0));
        $stats->setLastPostedAt(
            isset($row['last_posted_at']) && $row['last_posted_at'] !== null
                ? new \DateTimeImmutable((string) $row['last_posted_at'])
                : null,
        );
        if ($flush) {
            $this->entityManager->flush();
        }

        return true;
    }

    /**
     * One SQL pass for a topic id page. Rebuild must not walk posts per topic.
     *
     * @param list<int> $ids
     */
    public function rebuildTopicsByIds(array $ids): int
    {
        $ids = $this->positiveIds($ids);
        if ($ids === []) {
            return 0;
        }

        $list = implode(',', $ids);
        $visible = ForumDiscussionState::Visible->value;
        $held = ForumDiscussionState::Moderated->value;
        $deleted = ForumDiscussionState::Deleted->value;
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            "UPDATE cp_forum_topics t
             LEFT JOIN (
                SELECT topic_id,
                       SUM(discussion_state = '{$visible}') AS vis,
                       SUM(discussion_state = '{$held}') AS held,
                       SUM(discussion_state = '{$deleted}') AS del
                FROM cp_forum_posts
                WHERE topic_id IN ({$list})
                GROUP BY topic_id
             ) b ON b.topic_id = t.id
             LEFT JOIN (
                SELECT topic_id, id AS last_id, author_id, poster_name, created_at
                FROM (
                    SELECT topic_id, id, author_id, poster_name, created_at,
                           ROW_NUMBER() OVER (PARTITION BY topic_id ORDER BY created_at DESC, id DESC) AS rn
                    FROM cp_forum_posts
                    WHERE topic_id IN ({$list}) AND discussion_state = '{$visible}'
                ) ranked
                WHERE rn = 1
             ) lastp ON lastp.topic_id = t.id
             LEFT JOIN (
                SELECT topic_id, id AS first_id, body
                FROM (
                    SELECT topic_id, id, body,
                           ROW_NUMBER() OVER (PARTITION BY topic_id ORDER BY created_at ASC, id ASC) AS rn
                    FROM cp_forum_posts
                    WHERE topic_id IN ({$list})
                ) ranked
                WHERE rn = 1
             ) firstp ON firstp.topic_id = t.id
             SET t.post_count = COALESCE(b.vis, 0),
                 t.post_count_held = COALESCE(b.held, 0),
                 t.post_count_deleted = COALESCE(b.del, 0),
                 t.last_post_id = lastp.last_id,
                 t.last_poster_id = lastp.author_id,
                 t.last_poster_name = lastp.poster_name,
                 t.last_post_date = lastp.created_at,
                 t.first_post_id = firstp.first_id,
                 t.preview = LEFT(firstp.body, 128),
                 t.updated_at = CURRENT_TIMESTAMP
             WHERE t.id IN ({$list})",
        );

        return \count($ids);
    }

    /**
     * Tree roll-up for a section id page: one grouped UPDATE, not one COUNT per node.
     *
     * @param list<int> $ids
     */
    public function rebuildSectionsByIds(array $ids): int
    {
        $ids = $this->positiveIds($ids);
        if ($ids === []) {
            return 0;
        }

        $list = implode(',', $ids);
        $visible = ForumDiscussionState::Visible->value;
        $held = ForumDiscussionState::Moderated->value;
        $deleted = ForumDiscussionState::Deleted->value;
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            "UPDATE cp_forum_sections s
             LEFT JOIN (
                SELECT a.id AS section_id,
                       COALESCE(SUM(t.discussion_state = '{$visible}' AND t.moved_to_topic_id IS NULL), 0) AS vis_t,
                       COALESCE(SUM(t.discussion_state = '{$held}' AND t.moved_to_topic_id IS NULL), 0) AS held_t,
                       COALESCE(SUM(t.discussion_state = '{$deleted}' AND t.moved_to_topic_id IS NULL), 0) AS del_t
                FROM cp_forum_sections a
                INNER JOIN cp_forum_sections d ON d.id = a.id
                    OR d.parent_path LIKE CONCAT(COALESCE(NULLIF(a.parent_path, ''), CONCAT('/', a.id, '/')), '%')
                LEFT JOIN cp_forum_topics t ON t.section_id = d.id
                WHERE a.id IN ({$list})
                GROUP BY a.id
             ) tc ON tc.section_id = s.id
             LEFT JOIN (
                SELECT a.id AS section_id,
                       COALESCE(SUM(p.discussion_state = '{$visible}' AND t.discussion_state = '{$visible}' AND t.moved_to_topic_id IS NULL), 0) AS vis_p,
                       COALESCE(SUM(p.discussion_state = '{$held}' AND t.discussion_state <> '{$deleted}' AND t.moved_to_topic_id IS NULL), 0) AS held_p,
                       COALESCE(SUM(p.discussion_state = '{$deleted}' OR (t.discussion_state = '{$deleted}' AND t.moved_to_topic_id IS NULL)), 0) AS del_p
                FROM cp_forum_sections a
                INNER JOIN cp_forum_sections d ON d.id = a.id
                    OR d.parent_path LIKE CONCAT(COALESCE(NULLIF(a.parent_path, ''), CONCAT('/', a.id, '/')), '%')
                LEFT JOIN cp_forum_posts p ON p.section_id = d.id
                LEFT JOIN cp_forum_topics t ON t.id = p.topic_id
                WHERE a.id IN ({$list})
                GROUP BY a.id
             ) pc ON pc.section_id = s.id
             LEFT JOIN (
                SELECT ancestor_id, last_post_id, last_post_at, last_poster_id, last_poster_name, last_topic_id, last_topic_title
                FROM (
                    SELECT a.id AS ancestor_id,
                           p.id AS last_post_id,
                           p.created_at AS last_post_at,
                           p.author_id AS last_poster_id,
                           p.poster_name AS last_poster_name,
                           t.id AS last_topic_id,
                           t.title AS last_topic_title,
                           ROW_NUMBER() OVER (PARTITION BY a.id ORDER BY p.created_at DESC, p.id DESC) AS rn
                    FROM cp_forum_sections a
                    INNER JOIN cp_forum_sections d ON d.id = a.id
                        OR d.parent_path LIKE CONCAT(COALESCE(NULLIF(a.parent_path, ''), CONCAT('/', a.id, '/')), '%')
                    INNER JOIN cp_forum_posts p ON p.section_id = d.id
                    INNER JOIN cp_forum_topics t ON t.id = p.topic_id
                    WHERE a.id IN ({$list})
                      AND p.discussion_state = '{$visible}'
                      AND t.discussion_state = '{$visible}'
                      AND t.moved_to_topic_id IS NULL
                ) ranked
                WHERE rn = 1
             ) lp ON lp.ancestor_id = s.id
             SET s.topic_count = COALESCE(tc.vis_t, 0),
                 s.topic_count_held = COALESCE(tc.held_t, 0),
                 s.topic_count_deleted = COALESCE(tc.del_t, 0),
                 s.post_count = COALESCE(pc.vis_p, 0),
                 s.post_count_held = COALESCE(pc.held_p, 0),
                 s.post_count_deleted = COALESCE(pc.del_p, 0),
                 s.last_topic_id = lp.last_topic_id,
                 s.last_topic_title = lp.last_topic_title,
                 s.last_post_id = lp.last_post_id,
                 s.last_post_at = lp.last_post_at,
                 s.last_poster_id = lp.last_poster_id,
                 s.last_poster_name = lp.last_poster_name,
                 s.updated_at = CURRENT_TIMESTAMP
             WHERE s.id IN ({$list})",
        );

        return \count($ids);
    }

    /**
     * @param list<int> $ids
     */
    public function rebuildUsersByIds(array $ids): int
    {
        $ids = $this->positiveIds($ids);
        if ($ids === []) {
            return 0;
        }

        $list = implode(',', $ids);
        $visible = ForumDiscussionState::Visible->value;
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            "INSERT INTO cp_forum_user_stats (user_id, post_count, topic_count, like_received, warning_points, last_posted_at, updated_at)
             SELECT u.id,
                    COALESCE(p.visible_posts, 0),
                    COALESCE(t.visible_topics, 0),
                    COALESCE(s.like_received, 0),
                    COALESCE(s.warning_points, 0),
                    p.last_posted_at,
                    CURRENT_TIMESTAMP
             FROM cp_users u
             LEFT JOIN cp_forum_user_stats s ON s.user_id = u.id
             LEFT JOIN (
                SELECT p.author_id AS user_id,
                       SUM(p.discussion_state = '{$visible}' AND t.discussion_state = '{$visible}' AND t.moved_to_topic_id IS NULL) AS visible_posts,
                       MAX(CASE WHEN p.discussion_state = '{$visible}' AND t.discussion_state = '{$visible}' THEN p.created_at END) AS last_posted_at
                FROM cp_forum_posts p
                INNER JOIN cp_forum_topics t ON t.id = p.topic_id
                WHERE p.author_id IN ({$list})
                GROUP BY p.author_id
             ) p ON p.user_id = u.id
             LEFT JOIN (
                SELECT first_poster_id AS user_id,
                       SUM(discussion_state = '{$visible}' AND moved_to_topic_id IS NULL) AS visible_topics
                FROM cp_forum_topics
                WHERE first_poster_id IN ({$list})
                GROUP BY first_poster_id
             ) t ON t.user_id = u.id
             WHERE u.id IN ({$list})
             ON DUPLICATE KEY UPDATE
                post_count = VALUES(post_count),
                topic_count = VALUES(topic_count),
                last_posted_at = VALUES(last_posted_at),
                updated_at = VALUES(updated_at)",
        );

        return \count($ids);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int>
     */
    private function positiveIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    public function recountBoardStats(): void
    {
        $this->recountUserAndBoardStats(boardOnly: true);
    }

    private function recountUserAndBoardStats(bool $boardOnly = false): void
    {
        $conn = $this->entityManager->getConnection();

        if (!$boardOnly) {
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
