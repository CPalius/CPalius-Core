<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;

/**
 * Front-end bulk actions for selected topics/posts (vBulletin-style inline moderation).
 */
final class ForumInlineModerationService
{
    public function __construct(
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumModerationService $moderationService,
        private readonly ForumModerationLogService $moderationLog,
        private readonly ForumSplitService $splitService,
    ) {
    }

    /**
     * @param list<int> $topicIds
     * @param list<int> $postIds
     *
     * @return array{
     *     ok: bool,
     *     messageKey: string,
     *     messageParams: array<string, scalar>,
     *     removedTopicIds: list<int>,
     *     removedPostIds: list<int>,
     *     updatedTopicIds: list<int>,
     *     locked: ?bool,
     *     sticky: ?bool,
     *     redirectTopicId: ?int,
     *     redirectSlug: ?string
     * }
     */
    public function execute(
        User $actor,
        string $action,
        array $topicIds,
        array $postIds,
        ?int $targetSectionId,
        ?int $mergeTargetId,
        bool $keepRedirect,
        ?string $splitTitle = null,
    ): array {
        $empty = [
            'ok' => true,
            'messageKey' => 'forum.imod.done',
            'messageParams' => ['count' => 0],
            'removedTopicIds' => [],
            'removedPostIds' => [],
            'updatedTopicIds' => [],
            'locked' => null,
            'sticky' => null,
            'redirectTopicId' => null,
            'redirectSlug' => null,
        ];

        if ($action === 'delete_posts') {
            $posts = $this->loadPosts($postIds);
            if ($posts === []) {
                throw new \InvalidArgumentException('forum.imod.none_selected');
            }
            $removed = [];
            foreach ($posts as $post) {
                $id = $post->getId();
                if ($id !== null) {
                    $removed[] = $id;
                }
            }
            $this->moderationService->bulkDeletePosts($posts);
            $this->moderationLog->record($actor, 'inline_delete_posts', 'post', $removed[0] ?? 0, ['ids' => $removed]);
            $empty['removedPostIds'] = $removed;
            $empty['messageKey'] = 'forum.imod.posts_deleted';
            $empty['messageParams'] = ['count' => \count($removed)];

            return $empty;
        }

        if ($action === 'merge_posts') {
            $posts = $this->loadPosts($postIds);
            if (\count($posts) < 2) {
                throw new \InvalidArgumentException('forum.imod.merge_posts_min');
            }
            $ids = array_values(array_filter(array_map(static fn (ForumPost $p): ?int => $p->getId(), $posts)));
            $keeper = $this->moderationService->mergePosts($posts, $actor);
            $keeperId = $keeper->getId();
            $removed = array_values(array_filter($ids, static fn (int $id): bool => $id !== $keeperId));
            $this->moderationLog->record($actor, 'inline_merge_posts', 'post', (int) $keeperId, ['ids' => $removed]);
            $empty['removedPostIds'] = $removed;
            $empty['messageKey'] = 'forum.imod.posts_merged';
            $empty['messageParams'] = ['count' => \count($ids)];

            return $empty;
        }

        if ($action === 'split') {
            $posts = $this->loadPosts($postIds);
            if ($posts === []) {
                throw new \InvalidArgumentException('forum.imod.none_selected');
            }
            $title = trim((string) $splitTitle);
            if ($title === '') {
                throw new \InvalidArgumentException('forum.imod.split_title_required');
            }
            $source = $posts[0]->getTopic();
            $ids = array_values(array_filter(array_map(static fn (ForumPost $p): ?int => $p->getId(), $posts)));
            $newTopic = $this->splitService->split($source, $ids, $title, $actor);
            $this->moderationLog->record($actor, 'inline_split', 'topic', (int) $source->getId(), [
                'new_topic_id' => $newTopic->getId(),
                'title' => $title,
            ]);
            $empty['removedPostIds'] = $ids;
            $empty['redirectTopicId'] = $newTopic->getId();
            $empty['redirectSlug'] = $newTopic->getSlug();
            $empty['messageKey'] = 'forum.split.done';
            $empty['messageParams'] = ['count' => \count($ids)];

            return $empty;
        }

        $topics = $this->loadTopics($topicIds);
        if ($topics === []) {
            throw new \InvalidArgumentException('forum.imod.none_selected');
        }
        $ids = array_values(array_filter(array_map(static fn (ForumTopic $t): ?int => $t->getId(), $topics)));
        $mergeTarget = $action === 'merge' ? $this->resolveMergeTarget($topics, $mergeTargetId) : null;

        match ($action) {
            'lock' => $this->moderationService->bulkLock($topics, true),
            'unlock' => $this->moderationService->bulkLock($topics, false),
            'sticky' => $this->moderationService->bulkSticky($topics, true),
            'unsticky' => $this->moderationService->bulkSticky($topics, false),
            'delete', 'hide' => $this->moderationService->bulkSoftDelete($topics),
            'restore' => $this->moderationService->bulkRestore($topics),
            'move' => $this->move($topics, $targetSectionId, $keepRedirect),
            'merge' => $mergeTarget instanceof ForumTopic
                ? $this->merge($topics, $mergeTarget)
                : throw new \InvalidArgumentException('forum.imod.merge_min'),
            default => throw new \InvalidArgumentException('forum.imod.invalid_action'),
        };

        $this->moderationLog->record($actor, 'inline_'.$action, 'topic', $ids[0] ?? 0, [
            'ids' => $ids,
            'count' => \count($ids),
        ]);

        $empty['messageParams'] = ['count' => \count($ids)];
        $empty['updatedTopicIds'] = $ids;

        if (\in_array($action, ['delete', 'hide', 'move', 'merge'], true)) {
            $empty['removedTopicIds'] = $action === 'merge'
                ? array_values(array_filter($ids, static fn (int $id): bool => $id !== ($mergeTarget?->getId() ?? 0)))
                : $ids;
            if ($action === 'merge') {
                $targetId = $mergeTarget?->getId() ?? 0;
                $empty['updatedTopicIds'] = $targetId > 0 ? [$targetId] : [];
            } else {
                $empty['updatedTopicIds'] = [];
            }
        }

        $empty['locked'] = match ($action) {
            'lock' => true,
            'unlock' => false,
            default => null,
        };
        $empty['sticky'] = match ($action) {
            'sticky' => true,
            'unsticky' => false,
            default => null,
        };
        $empty['messageKey'] = match ($action) {
            'lock' => 'forum.imod.locked',
            'unlock' => 'forum.imod.unlocked',
            'sticky' => 'forum.imod.stuck',
            'unsticky' => 'forum.imod.unstuck',
            'delete', 'hide' => 'forum.imod.deleted',
            'restore' => 'forum.imod.restored',
            'move' => 'forum.imod.moved',
            'merge' => 'forum.imod.merged',
            default => 'forum.imod.done',
        };

        return $empty;
    }

    /**
     * @param list<ForumTopic> $topics
     */
    private function move(array $topics, ?int $targetSectionId, bool $keepRedirect): void
    {
        if ($targetSectionId === null || $targetSectionId < 1) {
            throw new \InvalidArgumentException('forum.imod.move_target_required');
        }
        $target = $this->sectionRepository->find($targetSectionId);
        if (!$target instanceof ForumSection || $target->isContainer() || !$target->allowsTopics()) {
            throw new \InvalidArgumentException('forum.imod.move_target_invalid');
        }
        $this->moderationService->bulkMove($topics, $target, $keepRedirect);
    }

    /**
     * @param list<ForumTopic> $topics
     */
    private function merge(array $topics, ForumTopic $target): void
    {
        $sources = [];
        foreach ($topics as $topic) {
            if ($topic->getId() !== $target->getId()) {
                $sources[] = $topic;
            }
        }
        if ($sources === []) {
            throw new \InvalidArgumentException('forum.imod.merge_min');
        }
        $this->moderationService->mergeInto($sources, $target);
    }

    /**
     * @param list<ForumTopic> $topics
     */
    private function resolveMergeTarget(array $topics, ?int $mergeTargetId): ForumTopic
    {
        if (\count($topics) < 2) {
            throw new \InvalidArgumentException('forum.imod.merge_min');
        }
        if ($mergeTargetId !== null) {
            foreach ($topics as $topic) {
                if ($topic->getId() === $mergeTargetId) {
                    return $topic;
                }
            }
        }

        return $topics[0];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<ForumTopic>
     */
    private function loadTopics(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        /** @var list<ForumTopic> $rows */
        $rows = $this->topicRepository->findBy(['id' => $ids]);
        $byId = [];
        foreach ($rows as $row) {
            $id = $row->getId();
            if ($id !== null) {
                $byId[$id] = $row;
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<ForumPost>
     */
    private function loadPosts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        /** @var list<ForumPost> $rows */
        $rows = $this->postRepository->findBy(['id' => $ids]);

        return $rows;
    }
}
