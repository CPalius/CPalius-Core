<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumBoardStats;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Entity\ForumTopicViewBuffer;
use Modules\Forum\Entity\ForumUserStats;
use Modules\Forum\ForumDiscussionState;
use Modules\Forum\Repository\ForumBoardStatsRepository;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumUserStatsRepository;

/**
 * Incremental forum counters. Hot path is += / -= plus one ancestor UPDATE.
 * COUNT(*) is illegal here — that lives on ForumStatsService / forum:recount.
 */
final class ForumCounterService
{
    /** @var array<int, ForumUserStats> */
    private array $userStatsRuntime = [];

    /** @var array<string, ForumBoardStats> */
    private array $boardStatsRuntime = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumUserStatsRepository $userStatsRepository,
        private readonly ForumBoardStatsRepository $boardStatsRepository,
    ) {
    }

    public function recordTopicView(ForumTopic $topic): void
    {
        $this->entityManager->persist(new ForumTopicViewBuffer($topic));
        $this->entityManager->flush();
    }

    public function incrementTopic(ForumTopic $topic): void
    {
        $section = $topic->getSection();
        $held = $topic->isModerated();

        $this->roll($section, $held
            ? ['topic_count_held' => 1]
            : ['topic_count' => 1]);

        $this->bumpBoard($section->getLocale(), $held
            ? ['topic_count_held' => 1]
            : ['topic_count' => 1]);

        if (!$held) {
            $author = $topic->getFirstPoster();
            if ($author instanceof User) {
                $stats = $this->userStats($author);
                $stats->setTopicCount($stats->getTopicCount() + 1);
            }
        }
    }

    public function incrementPost(ForumPost $post): void
    {
        $topic = $post->getTopic();
        $section = $post->getSection();
        $held = $post->isModerated();

        if ($held) {
            $topic->setPostCountHeld($topic->getPostCountHeld() + 1);
            $this->roll($section, ['post_count_held' => 1]);
            $this->bumpBoard($section->getLocale(), ['post_count_held' => 1]);

            return;
        }

        $topic->setPostCount($topic->getPostCount() + 1);
        $this->touchTopicLastPost($topic, $post);
        $this->roll($section, ['post_count' => 1]);
        $this->writeLastPostIfNewer($section, $post);
        $this->bumpBoard($section->getLocale(), ['post_count' => 1], $post);

        $author = $post->getAuthor();
        if ($author instanceof User) {
            $stats = $this->userStats($author);
            $stats->setPostCount($stats->getPostCount() + 1);
            $stats->setLastPostedAt($post->getCreatedAt());
        }
    }

    public function softDeletePost(ForumPost $post, ?User $actor = null): void
    {
        if ($post->getDiscussionState() === ForumDiscussionState::Deleted) {
            return;
        }

        $wasHeld = $post->isModerated();
        $wasVisible = $post->isVisible();
        $topic = $post->getTopic();
        $section = $post->getSection();

        $post->setDiscussionState(ForumDiscussionState::Deleted);
        $post->setDeletedAt(new \DateTimeImmutable());
        $post->setDeletedBy($actor);

        if ($wasHeld) {
            $topic->setPostCountHeld(max(0, $topic->getPostCountHeld() - 1));
            $topic->setPostCountDeleted($topic->getPostCountDeleted() + 1);
            $this->roll($section, ['post_count_held' => -1, 'post_count_deleted' => 1]);
            $this->bumpBoard($section->getLocale(), ['post_count_held' => -1]);
        } elseif ($wasVisible) {
            $topic->setPostCount(max(0, $topic->getPostCount() - 1));
            $topic->setPostCountDeleted($topic->getPostCountDeleted() + 1);
            $this->roll($section, ['post_count' => -1, 'post_count_deleted' => 1]);
            $this->bumpBoard($section->getLocale(), ['post_count' => -1]);

            $author = $post->getAuthor();
            if ($author instanceof User) {
                $stats = $this->userStats($author);
                $stats->setPostCount(max(0, $stats->getPostCount() - 1));
            }
        } else {
            $topic->setPostCountDeleted($topic->getPostCountDeleted() + 1);
        }

        if ($topic->getLastPostId() === $post->getId()) {
            $this->repairTopicLastPost($topic);
        }
        $this->repairSectionLastPostIfPointingAt($section, $post);
    }

    public function approvePost(ForumPost $post): void
    {
        if (!$post->isModerated()) {
            return;
        }

        $topic = $post->getTopic();
        $section = $post->getSection();
        $topicWasHeld = $topic->isModerated();

        $post->setDiscussionState(ForumDiscussionState::Visible);
        $topic->setPostCountHeld(max(0, $topic->getPostCountHeld() - 1));
        $topic->setPostCount($topic->getPostCount() + 1);

        $sectionDeltas = ['post_count_held' => -1, 'post_count' => 1];
        $boardDeltas = ['post_count_held' => -1, 'post_count' => 1];

        if ($topicWasHeld) {
            $topic->setDiscussionState(ForumDiscussionState::Visible);
            $sectionDeltas['topic_count_held'] = -1;
            $sectionDeltas['topic_count'] = 1;
            $boardDeltas['topic_count_held'] = -1;
            $boardDeltas['topic_count'] = 1;

            $opener = $topic->getFirstPoster();
            if ($opener instanceof User) {
                $stats = $this->userStats($opener);
                $stats->setTopicCount($stats->getTopicCount() + 1);
            }
        }

        $this->touchTopicLastPost($topic, $post);
        $this->roll($section, $sectionDeltas);
        $this->writeLastPostIfNewer($section, $post);
        $this->bumpBoard($section->getLocale(), $boardDeltas, $post);

        $author = $post->getAuthor();
        if ($author instanceof User) {
            $stats = $this->userStats($author);
            $stats->setPostCount($stats->getPostCount() + 1);
            $stats->setLastPostedAt($post->getCreatedAt());
        }
    }

    public function softDeleteTopic(ForumTopic $topic, ?User $actor = null): void
    {
        if ($topic->isDeleted()) {
            return;
        }

        $wasHeld = $topic->isModerated();
        $wasVisible = $topic->isVisible();
        $section = $topic->getSection();
        $visiblePosts = $topic->getPostCount();
        $heldPosts = $topic->getPostCountHeld();

        $topic->setDiscussionState(ForumDiscussionState::Deleted);
        $topic->setDeletedAt(new \DateTimeImmutable());
        $topic->setDeletedBy($actor);
        $topic->touch();

        $deltas = [];
        $board = [];
        if ($wasVisible) {
            $deltas['topic_count'] = -1;
            $deltas['topic_count_deleted'] = 1;
            $board['topic_count'] = -1;
            if ($visiblePosts > 0) {
                $deltas['post_count'] = -$visiblePosts;
                $deltas['post_count_deleted'] = $visiblePosts;
                $board['post_count'] = -$visiblePosts;
            }
            $opener = $topic->getFirstPoster();
            if ($opener instanceof User) {
                $stats = $this->userStats($opener);
                $stats->setTopicCount(max(0, $stats->getTopicCount() - 1));
            }
        } elseif ($wasHeld) {
            $deltas['topic_count_held'] = -1;
            $deltas['topic_count_deleted'] = 1;
            $board['topic_count_held'] = -1;
        }

        if ($heldPosts > 0) {
            $deltas['post_count_held'] = -$heldPosts;
            $board['post_count_held'] = -$heldPosts;
        }

        $this->roll($section, $deltas);
        $this->bumpBoard($section->getLocale(), $board);
        $this->repairSectionLastPostIfTopic($section, $topic);
    }

    public function restoreTopic(ForumTopic $topic): void
    {
        $section = $topic->getSection();
        $visiblePosts = $topic->getPostCount();
        $heldPosts = $topic->getPostCountHeld();

        $deltas = ['topic_count' => 1, 'topic_count_deleted' => -1];
        $board = ['topic_count' => 1];
        if ($visiblePosts > 0) {
            $deltas['post_count'] = $visiblePosts;
            $deltas['post_count_deleted'] = -$visiblePosts;
            $board['post_count'] = $visiblePosts;
        }
        if ($heldPosts > 0) {
            $deltas['post_count_held'] = $heldPosts;
            $board['post_count_held'] = $heldPosts;
        }

        $this->roll($section, $deltas);
        $this->bumpBoard($section->getLocale(), $board);

        $opener = $topic->getFirstPoster();
        if ($opener instanceof User) {
            $stats = $this->userStats($opener);
            $stats->setTopicCount($stats->getTopicCount() + 1);
        }

        $lastId = $topic->getLastPostId();
        if ($lastId !== null) {
            $last = $this->entityManager->find(ForumPost::class, $lastId);
            if ($last instanceof ForumPost && $last->isVisible()) {
                $this->writeLastPostIfNewer($section, $last);
                $this->bumpBoardLastIfNewer($section->getLocale(), $last);
            }
        }
    }

    public function removeTopic(ForumTopic $topic): void
    {
        $section = $topic->getSection();
        $visible = $topic->isVisible();
        $held = $topic->isModerated();
        $visiblePosts = $topic->getPostCount();
        $heldPosts = $topic->getPostCountHeld();
        $deletedPosts = $topic->getPostCountDeleted();

        $deltas = [];
        $board = [];
        if ($visible) {
            $deltas['topic_count'] = -1;
            $board['topic_count'] = -1;
        } elseif ($held) {
            $deltas['topic_count_held'] = -1;
            $board['topic_count_held'] = -1;
        } else {
            $deltas['topic_count_deleted'] = -1;
        }
        if ($visiblePosts > 0) {
            $deltas['post_count'] = -$visiblePosts;
            $board['post_count'] = -$visiblePosts;
        }
        if ($heldPosts > 0) {
            $deltas['post_count_held'] = -$heldPosts;
            $board['post_count_held'] = -$heldPosts;
        }
        if ($deletedPosts > 0) {
            $deltas['post_count_deleted'] = -$deletedPosts;
        }

        $this->roll($section, $deltas);
        $this->bumpBoard($section->getLocale(), $board);

        if ($visible) {
            $opener = $topic->getFirstPoster();
            if ($opener instanceof User) {
                $stats = $this->userStats($opener);
                $stats->setTopicCount(max(0, $stats->getTopicCount() - 1));
            }
        }

        $this->repairSectionLastPostIfTopic($section, $topic);
    }

    public function moveTopic(ForumTopic $topic, ForumSection $origin, ForumSection $target): void
    {
        if ($origin->getId() === $target->getId()) {
            return;
        }

        $this->roll($origin, $this->topicLocationDeltas($topic, -1));
        $this->roll($target, $this->topicLocationDeltas($topic, 1));
        $this->repairSectionLastPostIfTopic($origin, $topic);

        $lastId = $topic->getLastPostId();
        if ($lastId !== null && $topic->isVisible()) {
            $last = $this->entityManager->find(ForumPost::class, $lastId);
            if ($last instanceof ForumPost && $last->isVisible()) {
                $this->writeLastPostIfNewer($target, $last);
            }
        }
    }

    /**
     * Transfer already-moved posts' buckets from $source onto $target, then retire the source topic row.
     */
    public function mergeTopicInto(ForumTopic $source, ForumTopic $target): void
    {
        $visible = $source->getPostCount();
        $held = $source->getPostCountHeld();
        $deleted = $source->getPostCountDeleted();

        $target->setPostCount($target->getPostCount() + $visible);
        $target->setPostCountHeld($target->getPostCountHeld() + $held);
        $target->setPostCountDeleted($target->getPostCountDeleted() + $deleted);

        $origin = $source->getSection();
        $destination = $target->getSection();
        if ($origin->getId() !== $destination->getId()) {
            $this->roll($origin, [
                'post_count' => -$visible,
                'post_count_held' => -$held,
                'post_count_deleted' => -$deleted,
            ]);
            $this->roll($destination, [
                'post_count' => $visible,
                'post_count_held' => $held,
                'post_count_deleted' => $deleted,
            ]);
            $this->bumpBoard($origin->getLocale(), [
                'post_count' => -$visible,
                'post_count_held' => -$held,
            ]);
            $this->bumpBoard($destination->getLocale(), [
                'post_count' => $visible,
                'post_count_held' => $held,
            ]);
        }

        $source->setPostCount(0);
        $source->setPostCountHeld(0);
        $source->setPostCountDeleted(0);

        $this->softDeleteTopic($source);

        $lastId = $target->getLastPostId();
        if ($lastId !== null && $target->isVisible()) {
            $last = $this->entityManager->find(ForumPost::class, $lastId);
            if ($last instanceof ForumPost && $last->isVisible()) {
                $this->touchTopicLastPost($target, $last);
                $this->writeLastPostIfNewer($destination, $last);
            }
        }
    }

    /**
     * @param list<ForumPost> $posts
     */
    public function splitPosts(ForumTopic $source, ForumTopic $newTopic, array $posts): void
    {
        $visible = 0;
        $held = 0;
        $deleted = 0;
        foreach ($posts as $post) {
            match ($post->getDiscussionState()) {
                ForumDiscussionState::Visible => ++$visible,
                ForumDiscussionState::Moderated => ++$held,
                ForumDiscussionState::Deleted => ++$deleted,
            };
        }

        $source->setPostCount(max(0, $source->getPostCount() - $visible));
        $source->setPostCountHeld(max(0, $source->getPostCountHeld() - $held));
        $source->setPostCountDeleted(max(0, $source->getPostCountDeleted() - $deleted));
        $newTopic->setPostCount($visible);
        $newTopic->setPostCountHeld($held);
        $newTopic->setPostCountDeleted($deleted);

        $origin = $source->getSection();
        $destination = $newTopic->getSection();
        $newHeld = $newTopic->isModerated();

        if ($origin->getId() === $destination->getId()) {
            $this->roll($origin, $newHeld ? ['topic_count_held' => 1] : ['topic_count' => 1]);
            $this->bumpBoard($origin->getLocale(), $newHeld ? ['topic_count_held' => 1] : ['topic_count' => 1]);
        } else {
            $this->roll($origin, [
                'post_count' => -$visible,
                'post_count_held' => -$held,
                'post_count_deleted' => -$deleted,
            ]);
            $this->roll($destination, [
                'post_count' => $visible,
                'post_count_held' => $held,
                'post_count_deleted' => $deleted,
                'topic_count' => $newHeld ? 0 : 1,
                'topic_count_held' => $newHeld ? 1 : 0,
            ]);
            $this->bumpBoard($origin->getLocale(), [
                'post_count' => -$visible,
                'post_count_held' => -$held,
            ]);
            $this->bumpBoard($destination->getLocale(), [
                'post_count' => $visible,
                'post_count_held' => $held,
                'topic_count' => $newHeld ? 0 : 1,
                'topic_count_held' => $newHeld ? 1 : 0,
            ]);
        }

        if (!$newHeld) {
            $opener = $newTopic->getFirstPoster();
            if ($opener instanceof User) {
                $stats = $this->userStats($opener);
                $stats->setTopicCount($stats->getTopicCount() + 1);
            }
        }

        $this->repairTopicLastPost($source);
        $last = $posts !== [] ? $posts[\count($posts) - 1] : null;
        if ($last instanceof ForumPost && $last->isVisible() && $newTopic->isVisible()) {
            $this->writeLastPostIfNewer($destination, $last);
        }
    }

    /**
     * @return array<string, int>
     */
    private function topicLocationDeltas(ForumTopic $topic, int $sign): array
    {
        $deltas = [];
        if ($topic->isVisible()) {
            $deltas['topic_count'] = $sign;
        } elseif ($topic->isModerated()) {
            $deltas['topic_count_held'] = $sign;
        } else {
            $deltas['topic_count_deleted'] = $sign;
        }

        $visible = $topic->getPostCount();
        $held = $topic->getPostCountHeld();
        $deleted = $topic->getPostCountDeleted();
        if ($visible !== 0) {
            $deltas['post_count'] = $sign * $visible;
        }
        if ($held !== 0) {
            $deltas['post_count_held'] = $sign * $held;
        }
        if ($deleted !== 0) {
            $deltas['post_count_deleted'] = $sign * $deleted;
        }

        return $deltas;
    }

    /**
     * @param array<string, int> $deltas
     */
    private function roll(ForumSection $section, array $deltas): void
    {
        $ids = $this->ancestorIds($section);
        $deltas = array_filter($deltas, static fn (int $d): bool => $d !== 0);
        if ($ids === [] || $deltas === []) {
            return;
        }

        $sets = [];
        $params = [];
        foreach ($deltas as $column => $delta) {
            $sets[] = sprintf('%s = GREATEST(0, %s + :d_%s)', $column, $column, $column);
            $params['d_'.$column] = $delta;
        }
        $sets[] = 'updated_at = :now';
        $params['now'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $in = implode(',', array_map('intval', $ids));
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE cp_forum_sections SET '.implode(', ', $sets).' WHERE id IN ('.$in.')',
            $params,
        );

        foreach ($ids as $id) {
            $managed = $this->entityManager->find(ForumSection::class, $id);
            if ($managed instanceof ForumSection) {
                $this->applySectionDeltas($managed, $deltas);
                $managed->touch();
            }
        }
    }

    /**
     * @param array<string, int> $deltas
     */
    private function applySectionDeltas(ForumSection $section, array $deltas): void
    {
        foreach ($deltas as $column => $delta) {
            match ($column) {
                'topic_count' => $section->setTopicCount(max(0, $section->getTopicCount() + $delta)),
                'post_count' => $section->setPostCount(max(0, $section->getPostCount() + $delta)),
                'topic_count_held' => $section->setTopicCountHeld($section->getTopicCountHeld() + $delta),
                'topic_count_deleted' => $section->setTopicCountDeleted($section->getTopicCountDeleted() + $delta),
                'post_count_held' => $section->setPostCountHeld($section->getPostCountHeld() + $delta),
                'post_count_deleted' => $section->setPostCountDeleted($section->getPostCountDeleted() + $delta),
                default => null,
            };
        }
    }

    /**
     * @return list<int>
     */
    private function ancestorIds(ForumSection $section): array
    {
        $ids = $section->ancestorIds();
        if (\count($ids) > 1 || $section->getParent() === null) {
            return $ids !== [] ? $ids : ($section->getId() !== null ? [$section->getId()] : []);
        }

        $walked = [];
        $cursor = $section;
        $guard = 0;
        while ($cursor instanceof ForumSection && $guard++ < 32) {
            $id = $cursor->getId();
            if ($id !== null) {
                $walked[] = $id;
            }
            $cursor = $cursor->getParent();
        }

        return $walked !== [] ? array_values(array_unique($walked)) : $ids;
    }

    private function writeLastPostIfNewer(ForumSection $section, ForumPost $post): void
    {
        $ids = $this->ancestorIds($section);
        if ($ids === [] || !$post->isVisible()) {
            return;
        }

        $topic = $post->getTopic();
        $at = $post->getCreatedAt();
        $params = [
            'tid' => $topic->getId(),
            'title' => $topic->getTitle(),
            'pid' => $post->getId(),
            'at' => $at->format('Y-m-d H:i:s'),
            'uid' => $post->getAuthor()?->getId(),
            'name' => $post->getPosterName(),
            'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        $in = implode(',', array_map('intval', $ids));
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE cp_forum_sections SET
                last_topic_id = :tid,
                last_topic_title = :title,
                last_post_id = :pid,
                last_post_at = :at,
                last_poster_id = :uid,
                last_poster_name = :name,
                updated_at = :now
             WHERE id IN ('.$in.')
               AND (last_post_at IS NULL OR last_post_at < :at OR (last_post_at = :at AND (last_post_id IS NULL OR last_post_id < :pid)))',
            $params,
        );

        foreach ($ids as $id) {
            $managed = $this->entityManager->find(ForumSection::class, $id);
            if (!$managed instanceof ForumSection) {
                continue;
            }
            $current = $managed->getLastPostAt();
            if ($current !== null && $current > $at) {
                continue;
            }
            if ($current !== null && $current == $at && ($managed->getLastPostId() ?? 0) >= ($post->getId() ?? 0)) {
                continue;
            }
            $managed->setLastTopicId($topic->getId());
            $managed->setLastTopicTitle($topic->getTitle());
            $managed->setLastPostId($post->getId());
            $managed->setLastPostAt($at);
            $managed->setLastPoster($post->getAuthor());
            $managed->setLastPosterName($post->getPosterName());
            $managed->touch();
        }
    }

    private function touchTopicLastPost(ForumTopic $topic, ForumPost $post): void
    {
        $current = $topic->getLastPostDate();
        if ($current !== null && $current > $post->getCreatedAt()) {
            return;
        }

        $topic->setLastPoster($post->getAuthor());
        $topic->setLastPosterName($post->getPosterName());
        $topic->setLastPostId($post->getId());
        $topic->setLastPostDate($post->getCreatedAt());
        $topic->touch();
    }

    private function repairTopicLastPost(ForumTopic $topic): void
    {
        $last = $this->postRepository->findLastVisibleByTopic($topic);
        if ($last === null) {
            $topic->setLastPoster(null);
            $topic->setLastPosterName($topic->getFirstPosterName());
            $topic->setLastPostId($topic->getFirstPostId());
            $topic->setLastPostDate(null);
            $topic->touch();

            return;
        }

        $this->touchTopicLastPost($topic, $last);
    }

    private function repairSectionLastPostIfPointingAt(ForumSection $section, ForumPost $post): void
    {
        $postId = $post->getId();
        foreach ($this->managedAncestors($section) as $node) {
            if ($node->getLastPostId() === $postId) {
                $this->repairSectionLastPost($node);
            }
        }
    }

    private function repairSectionLastPostIfTopic(ForumSection $section, ForumTopic $topic): void
    {
        $topicId = $topic->getId();
        foreach ($this->managedAncestors($section) as $node) {
            if ($node->getLastTopicId() === $topicId) {
                $this->repairSectionLastPost($node);
            }
        }
    }

    private function repairSectionLastPost(ForumSection $section): void
    {
        $last = $this->findLastVisibleInSubtree($section);
        if ($last === null) {
            $section->setLastTopicId(null);
            $section->setLastTopicTitle(null);
            $section->setLastPostId(null);
            $section->setLastPostAt(null);
            $section->setLastPoster(null);
            $section->setLastPosterName(null);
            $section->touch();
            $this->persistSectionLastPost($section);

            return;
        }

        $topic = $last->getTopic();
        $section->setLastTopicId($topic->getId());
        $section->setLastTopicTitle($topic->getTitle());
        $section->setLastPostId($last->getId());
        $section->setLastPostAt($last->getCreatedAt());
        $section->setLastPoster($last->getAuthor());
        $section->setLastPosterName($last->getPosterName());
        $section->touch();
        $this->persistSectionLastPost($section);
    }

    private function persistSectionLastPost(ForumSection $section): void
    {
        $id = $section->getId();
        if ($id === null) {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE cp_forum_sections SET
                last_topic_id = :tid,
                last_topic_title = :title,
                last_post_id = :pid,
                last_post_at = :at,
                last_poster_id = :uid,
                last_poster_name = :name,
                updated_at = :now
             WHERE id = :id',
            [
                'tid' => $section->getLastTopicId(),
                'title' => $section->getLastTopicTitle(),
                'pid' => $section->getLastPostId(),
                'at' => $section->getLastPostAt()?->format('Y-m-d H:i:s'),
                'uid' => $section->getLastPoster()?->getId(),
                'name' => $section->getLastPosterName(),
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );
    }

    private function findLastVisibleInSubtree(ForumSection $section): ?ForumPost
    {
        $id = $section->getId();
        if ($id === null) {
            return null;
        }

        $path = $section->getParentPath() !== '' ? $section->getParentPath() : '/'.$id.'/';
        $postId = $this->entityManager->getConnection()->fetchOne(
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

        if ($postId === false || $postId === null) {
            return null;
        }

        return $this->entityManager->find(ForumPost::class, (int) $postId);
    }

    /**
     * @return list<ForumSection>
     */
    private function managedAncestors(ForumSection $section): array
    {
        $out = [];
        foreach ($this->ancestorIds($section) as $id) {
            $managed = $this->entityManager->find(ForumSection::class, $id);
            if ($managed instanceof ForumSection) {
                $out[] = $managed;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $deltas
     */
    private function bumpBoard(string $locale, array $deltas, ?ForumPost $lastPost = null): void
    {
        $row = $this->boardStats($locale);
        foreach ($deltas as $column => $delta) {
            if ($delta === 0) {
                continue;
            }
            match ($column) {
                'topic_count' => $row->setTopicCount($row->getTopicCount() + $delta),
                'post_count' => $row->setPostCount($row->getPostCount() + $delta),
                'topic_count_held' => $row->setTopicCountHeld($row->getTopicCountHeld() + $delta),
                'post_count_held' => $row->setPostCountHeld($row->getPostCountHeld() + $delta),
                default => null,
            };
        }

        if ($lastPost instanceof ForumPost && $lastPost->isVisible()) {
            $this->applyBoardLastIfNewer($row, $lastPost);
        }
    }

    private function bumpBoardLastIfNewer(string $locale, ForumPost $post): void
    {
        $this->applyBoardLastIfNewer($this->boardStats($locale), $post);
    }

    private function applyBoardLastIfNewer(ForumBoardStats $row, ForumPost $post): void
    {
        $current = $row->getLastPostAt();
        if ($current !== null && $current > $post->getCreatedAt()) {
            return;
        }

        $row->setLastTopicId($post->getTopic()->getId());
        $row->setLastPostId($post->getId());
        $row->setLastPostAt($post->getCreatedAt());
        $row->setLastPoster($post->getAuthor());
        $row->setLastPosterName($post->getPosterName());
    }

    private function userStats(User $user): ForumUserStats
    {
        $userId = $user->getId() ?? 0;
        if (isset($this->userStatsRuntime[$userId])) {
            return $this->userStatsRuntime[$userId];
        }

        $row = $this->userStatsRepository->findOneByUser($user);
        if (!$row instanceof ForumUserStats) {
            $row = new ForumUserStats($user);
            $this->entityManager->persist($row);
        }

        return $this->userStatsRuntime[$userId] = $row;
    }

    private function boardStats(string $locale): ForumBoardStats
    {
        if (isset($this->boardStatsRuntime[$locale])) {
            return $this->boardStatsRuntime[$locale];
        }

        $row = $this->boardStatsRepository->findOneByLocale($locale);
        if (!$row instanceof ForumBoardStats) {
            $row = new ForumBoardStats($locale);
            $this->entityManager->persist($row);
        }

        return $this->boardStatsRuntime[$locale] = $row;
    }
}
