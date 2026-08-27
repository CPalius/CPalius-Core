<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\ForumTopic;
use App\Entity\User;
use App\Repository\ForumPostRepository;
use App\Repository\ForumTopicRepository;
use App\Repository\UserRepository;

/**
 * Forum ana sayfası "Son olaylar" panosu — sekmeli veri + AJAX load-more.
 */
final class ForumActivityService
{
    public const TAB_LATEST_TOPICS = 'latest_topics';
    public const TAB_LATEST_POSTS = 'latest_posts';
    public const TAB_NEWEST_USERS = 'newest_users';
    public const TAB_TOP_POSTERS = 'top_posters';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly ForumTopicRepository $topicRepository,
        private readonly ForumPostRepository $postRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.activity_enabled', true);
    }

    /**
     * @return list<string>
     */
    public function enabledTabs(): array
    {
        $tabs = [];
        if ((bool) $this->settingsRegistry->get('forum.activity_show_latest_topics', true)) {
            $tabs[] = self::TAB_LATEST_TOPICS;
        }
        if ((bool) $this->settingsRegistry->get('forum.activity_show_latest_posts', true)) {
            $tabs[] = self::TAB_LATEST_POSTS;
        }
        if ((bool) $this->settingsRegistry->get('forum.activity_show_newest_users', true)) {
            $tabs[] = self::TAB_NEWEST_USERS;
        }
        if ((bool) $this->settingsRegistry->get('forum.activity_show_top_posters', true)) {
            $tabs[] = self::TAB_TOP_POSTERS;
        }

        return $tabs;
    }

    public function perTab(): int
    {
        $n = (int) $this->settingsRegistry->get('forum.activity_per_tab', 5);

        return $n > 0 ? $n : 5;
    }

    public function loadMoreStep(): int
    {
        $n = (int) $this->settingsRegistry->get('forum.activity_load_more', 5);

        return $n > 0 ? $n : 5;
    }

    /**
     * İlk render için tüm açık sekmelerin ilk dilimi.
     *
     * @return array{
     *     enabled: bool,
     *     tabs: list<string>,
     *     perTab: int,
     *     loadMore: int,
     *     initial: array<string, array{items: list<array<string, mixed>>, hasMore: bool}>
     * }
     */
    public function buildPanelState(): array
    {
        if (!$this->isEnabled()) {
            return [
                'enabled' => false,
                'tabs' => [],
                'perTab' => $this->perTab(),
                'loadMore' => $this->loadMoreStep(),
                'initial' => [],
            ];
        }

        $tabs = $this->enabledTabs();
        $perTab = $this->perTab();
        $initial = [];
        foreach ($tabs as $tab) {
            $initial[$tab] = $this->fetchTab($tab, 0, $perTab);
        }

        return [
            'enabled' => $tabs !== [],
            'tabs' => $tabs,
            'perTab' => $perTab,
            'loadMore' => $this->loadMoreStep(),
            'initial' => $initial,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, hasMore: bool}
     */
    public function fetchTab(string $tab, int $offset, int $limit): array
    {
        if (!\in_array($tab, $this->enabledTabs(), true)) {
            return ['items' => [], 'hasMore' => false];
        }

        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        // hasMore için bir fazla çek
        $rows = match ($tab) {
            self::TAB_LATEST_TOPICS => $this->mapTopics(
                $this->topicRepository->findNewestOpened($limit + 1, $offset),
                self::TAB_LATEST_TOPICS,
                useCreatedAt: true
            ),
            self::TAB_LATEST_POSTS => $this->mapTopics(
                $this->topicRepository->findLatestReplied($limit + 1, $offset),
                self::TAB_LATEST_POSTS,
                useCreatedAt: false
            ),
            self::TAB_NEWEST_USERS => $this->mapUsers($this->findNewestUsers($limit + 1, $offset)),
            self::TAB_TOP_POSTERS => $this->mapTopPosters($limit + 1, $offset),
            default => [],
        };

        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        return ['items' => $rows, 'hasMore' => $hasMore];
    }

    /**
     * @param list<ForumTopic> $topics
     *
     * @return list<array<string, mixed>>
     */
    private function mapTopics(array $topics, string $type, bool $useCreatedAt): array
    {
        $items = [];
        foreach ($topics as $topic) {
            $postCount = $topic->getPostCount();
            $items[] = [
                'type' => $type,
                'layout' => 'thread',
                'id' => $topic->getId(),
                'title' => $topic->getTitle(),
                'slug' => $topic->getSlug() ?? '',
                'topicId' => $topic->getId(),
                'sectionTitle' => $topic->getSection()->getTitle(),
                'sectionSlug' => $topic->getSection()->getSlug(),
                'author' => $topic->getFirstPosterName(),
                'authorId' => $topic->getFirstPoster()?->getId(),
                'lastPoster' => $topic->getLastPosterName() ?: $topic->getFirstPosterName(),
                'lastPosterId' => $topic->getLastPoster()?->getId() ?? $topic->getFirstPoster()?->getId(),
                'replyCount' => max(0, $postCount - 1),
                'viewCount' => $topic->getViewCount(),
                'at' => $useCreatedAt ? $topic->getCreatedAt() : $topic->getUpdatedAt(),
            ];
        }

        return $items;
    }

    /**
     * @return list<User>
     */
    private function findNewestUsers(int $limit, int $offset): array
    {
        return $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<User> $users
     *
     * @return list<array<string, mixed>>
     */
    private function mapUsers(array $users): array
    {
        $items = [];
        foreach ($users as $user) {
            $name = $this->resolveMemberLabel($user);
            $items[] = [
                'type' => self::TAB_NEWEST_USERS,
                'layout' => 'user',
                'id' => $user->getId(),
                'title' => $name,
                'authorId' => $user->getId(),
                'author' => $name,
                'at' => $user->getCreatedAt(),
                'replyCount' => null,
                'viewCount' => null,
                'meta' => '',
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapTopPosters(int $limit, int $offset): array
    {
        $counts = $this->postRepository->findMemberPostCounts($limit, $offset);
        if ($counts === []) {
            return [];
        }

        $users = $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', array_keys($counts))
            ->getQuery()
            ->getResult();

        /** @var array<int, User> $byId */
        $byId = [];
        foreach ($users as $user) {
            $byId[(int) $user->getId()] = $user;
        }

        $items = [];
        foreach ($counts as $userId => $postCount) {
            $user = $byId[$userId] ?? null;
            if ($user === null) {
                continue;
            }
            $name = $this->resolveMemberLabel($user);
            $items[] = [
                'type' => self::TAB_TOP_POSTERS,
                'layout' => 'user',
                'id' => $userId,
                'title' => $name,
                'authorId' => $userId,
                'author' => $name,
                'at' => $user->getCreatedAt(),
                'postCount' => $postCount,
                'replyCount' => $postCount,
                'viewCount' => null,
            ];
        }

        return $items;
    }

    /**
     * Forum listelerinde e-posta yerine kullanıcı adı (yoksa ad soyad).
     */
    private function resolveMemberLabel(User $user): string
    {
        $username = trim((string) ($user->getUsername() ?? ''));
        if ($username !== '') {
            return $username;
        }

        $fullName = trim($user->getFirstName().' '.$user->getLastName());
        if ($fullName !== '') {
            return $fullName;
        }

        return (string) $user->getEmail();
    }
}
