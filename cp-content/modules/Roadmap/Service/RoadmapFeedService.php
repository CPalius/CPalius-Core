<?php

declare(strict_types=1);

namespace Modules\Roadmap\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\NodeRepository;
use Modules\Roadmap\Contract\RoadmapForumFeedProviderInterface;
use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Modules\Roadmap\Dto\RoadmapFeedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Merge native, blog-category, and forum sources into one chronological RoadmapFeedItem list.
 */
final class RoadmapFeedService
{
    private const NODE_TYPE_POST = 'post';
    private const SOURCE_FETCH_CAP = 80;

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly RoadmapEntryRepository $entryRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly AssetRepository $assetRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?RoadmapForumFeedProviderInterface $forumFeedProvider = null,
    ) {
    }

    /**
     * @return array{
     *     nativeEntries: list<RoadmapEntry>,
     *     moduleTimeline: list<RoadmapFeedItem>,
     *     blogItems: list<RoadmapFeedItem>,
     *     forumItems: list<RoadmapFeedItem>,
     *     recentCount: int,
     *     page: int,
     *     perPage: int,
     *     total: int,
     *     statusFilter: ?string
     * }
     */
    public function buildPage(string $locale, int $page = 1, ?string $statusFilter = null): array
    {
        $page = max(1, $page);
        $nativeLimit = max(1, (int) $this->settingsRegistry->get('roadmap.native_limit', 8));
        $blogLimit = max(0, (int) $this->settingsRegistry->get('roadmap.blog_limit', 5));
        $forumLimit = max(0, (int) $this->settingsRegistry->get('roadmap.forum_limit', 5));
        $status = $this->normalizeStatus($statusFilter);

        $allNative = $this->isEnabled('roadmap.native_enabled', true)
            ? $this->entryRepository->findPublicNativeEntries($locale, $status, 200)
            : [];
        $total = \count($allNative);
        $offset = ($page - 1) * $nativeLimit;
        $nativeEntries = \array_slice($allNative, $offset, $nativeLimit);

        $blogItems = [];
        $forumItems = [];
        $includeExternal = $status === null || $status === RoadmapEntry::STATUS_SHIPPED;

        if ($includeExternal && $blogLimit > 0 && $this->isEnabled('roadmap.blog_enabled', false)) {
            foreach ($this->fetchBlogPosts($locale, $blogLimit) as $post) {
                $blogItems[] = $this->mapBlog($post, $locale);
            }
        }

        if ($includeExternal && $forumLimit > 0 && $this->isEnabled('roadmap.forum_enabled', false)) {
            $forumItems = $this->fetchForumItems($locale, $forumLimit);
        }

        $moduleTimeline = array_merge($blogItems, $forumItems);
        usort(
            $moduleTimeline,
            static fn (RoadmapFeedItem $a, RoadmapFeedItem $b): int => $b->occurredAt <=> $a->occurredAt,
        );

        $moduleTunnelRows = $this->buildTunnelRows($blogItems, $forumItems);

        $since = (new \DateTimeImmutable('today'))->modify('-6 days');
        $recentCount = $this->countRecentAcrossSources($locale, $since);

        return [
            'nativeEntries' => $nativeEntries,
            'moduleTimeline' => $moduleTimeline,
            'moduleTunnelRows' => $moduleTunnelRows,
            'blogItems' => $blogItems,
            'forumItems' => $forumItems,
            'recentCount' => $recentCount,
            'page' => $page,
            'perPage' => $nativeLimit,
            'total' => $total,
            'statusFilter' => $status,
        ];
    }

    /**
     * Pair blog (left) and forum (right) on the same row; no chronological merge.
     *
     * @param list<RoadmapFeedItem> $blogItems
     * @param list<RoadmapFeedItem> $forumItems
     *
     * @return list<array{blog: ?RoadmapFeedItem, forum: ?RoadmapFeedItem}>
     */
    private function buildTunnelRows(array $blogItems, array $forumItems): array
    {
        $max = max(\count($blogItems), \count($forumItems));
        $rows = [];
        for ($i = 0; $i < $max; ++$i) {
            $rows[] = [
                'blog' => $blogItems[$i] ?? null,
                'forum' => $forumItems[$i] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Homepage portal payload: native, blog, and forum with separate limits.
     *
     * @return array{
     *     native: list<RoadmapFeedItem>,
     *     blog: list<RoadmapFeedItem>,
     *     forum: list<RoadmapFeedItem>,
     *     recentCount: int
     * }
     */
    public function buildPortalSnapshot(string $locale, ?int $nativeLimit = null, ?int $blogLimit = null, ?int $forumLimit = null): array
    {
        $nativeLimit = max(0, $nativeLimit ?? (int) $this->settingsRegistry->get('roadmap.portal_native_limit', 3));
        $blogLimit = max(0, $blogLimit ?? (int) $this->settingsRegistry->get('roadmap.portal_blog_limit', 3));
        $forumLimit = max(0, $forumLimit ?? (int) $this->settingsRegistry->get('roadmap.portal_forum_limit', 3));

        $native = [];
        if ($nativeLimit > 0 && $this->isEnabled('roadmap.native_enabled', true)) {
            foreach ($this->entryRepository->findPublicNativeEntries($locale, null, $nativeLimit) as $entry) {
                $native[] = $this->mapNative($entry, $locale);
            }
        }

        $blog = [];
        if ($blogLimit > 0 && $this->isEnabled('roadmap.blog_enabled', false)) {
            foreach ($this->fetchBlogPosts($locale, $blogLimit) as $post) {
                $blog[] = $this->mapBlog($post, $locale);
            }
        }

        $forum = [];
        if ($forumLimit > 0 && $this->isEnabled('roadmap.forum_enabled', false)) {
            $forum = $this->fetchForumItems($locale, $forumLimit);
        }

        $since = (new \DateTimeImmutable('today'))->modify('-6 days');

        return [
            'native' => $native,
            'blog' => $blog,
            'forum' => $forum,
            // Backward compatible keys for the older portal Twig.
            'milestones' => $native,
            'updates' => array_merge($blog, $forum),
            'recentCount' => $this->countRecentAcrossSources($locale, $since),
        ];
    }

    /**
     * Left column: blog + forum (native Studio rows stay on the right).
     *
     * @return list<RoadmapFeedItem>
     */
    private function collectExternalFeedItems(string $locale, ?string $status): array
    {
        $items = [];

        // External sources are already published; apply status only for shipped or unfiltered.
        $includeExternal = $status === null || $status === RoadmapEntry::STATUS_SHIPPED;

        if ($includeExternal && $this->isEnabled('roadmap.blog_enabled', false)) {
            foreach ($this->fetchBlogPosts($locale) as $post) {
                $items[] = $this->mapBlog($post, $locale);
            }
        }

        if ($includeExternal && $this->isEnabled('roadmap.forum_enabled', false)) {
            array_push($items, ...$this->fetchForumItems($locale));
        }

        usort(
            $items,
            static fn (RoadmapFeedItem $a, RoadmapFeedItem $b): int => $b->occurredAt <=> $a->occurredAt,
        );

        return $items;
    }

    /**
     * Combined native + external list for the portal and recent counter.
     *
     * @return list<RoadmapFeedItem>
     */
    private function collectFeedItems(string $locale, ?string $status): array
    {
        $items = $this->collectExternalFeedItems($locale, $status);

        if ($this->isEnabled('roadmap.native_enabled', true)) {
            foreach ($this->entryRepository->findPublicNativeEntries($locale, $status, self::SOURCE_FETCH_CAP) as $entry) {
                $items[] = $this->mapNative($entry, $locale);
            }
        }

        usort(
            $items,
            static fn (RoadmapFeedItem $a, RoadmapFeedItem $b): int => $b->occurredAt <=> $a->occurredAt,
        );

        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $key = $item->source.'|'.($item->slug ?? $item->url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        return $unique;
    }

    /**
     * @return list<Node>
     */
    private function fetchBlogPosts(string $locale, int $limit = self::SOURCE_FETCH_CAP): array
    {
        $categoryId = (int) $this->settingsRegistry->get('roadmap.blog_category_id', 0);
        if ($categoryId <= 0 || $limit <= 0) {
            return [];
        }

        return $this->nodeRepository
            ->createPublishedByCategoryQueryBuilder($categoryId, self::NODE_TYPE_POST, $locale)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<RoadmapFeedItem>
     */
    private function fetchForumItems(string $locale, int $limit = self::SOURCE_FETCH_CAP): array
    {
        if ($this->forumFeedProvider === null) {
            return [];
        }

        $sectionId = (int) $this->settingsRegistry->get('roadmap.forum_section_id', 0);
        if ($sectionId <= 0 || $limit <= 0) {
            return [];
        }

        $userIds = $this->parseUserIds((string) $this->settingsRegistry->get('roadmap.forum_user_ids', ''));

        return $this->forumFeedProvider->fetchTopics($locale, $sectionId, $limit, $userIds);
    }

    private function mapNative(RoadmapEntry $entry, string $locale): RoadmapFeedItem
    {
        return new RoadmapFeedItem(
            source: RoadmapFeedItem::SOURCE_NATIVE,
            title: $entry->getTitle(),
            excerpt: $entry->getSummary(),
            url: $this->urlGenerator->generate('roadmap_show', [
                '_locale' => $locale,
                'slug' => $entry->getSlug(),
            ]),
            occurredAt: $entry->getPublishedAt() ?? $entry->getUpdatedAt(),
            status: $entry->getStatus(),
            badge: 'Roadmap',
            icon: $entry->getIcon() ?: 'bi-signpost-2',
            versionLabel: $entry->getVersionLabel(),
            kind: $entry->getKind(),
            slug: $entry->getSlug(),
        );
    }

    private function mapBlog(Node $post, string $locale): RoadmapFeedItem
    {
        $excerpt = (string) ($post->getDataValue('excerpt') ?? '');
        if ($excerpt === '') {
            $body = strip_tags((string) ($post->getDataValue('body') ?? ''));
            $excerpt = mb_substr($body, 0, 160);
        }

        $author = $post->getAuthor();
        [$authorName, $avatarUrl, $authorId] = $this->resolveAuthor($author);

        return new RoadmapFeedItem(
            source: RoadmapFeedItem::SOURCE_BLOG,
            title: $post->getTitle(),
            excerpt: $excerpt,
            url: $this->urlGenerator->generate('blog_show', [
                '_locale' => $locale,
                'slug' => $post->getSlug(),
            ]),
            occurredAt: $post->getPublishedAt() ?? new \DateTimeImmutable(),
            status: RoadmapEntry::STATUS_SHIPPED,
            badge: 'Blog',
            icon: 'bi-newspaper',
            slug: $post->getSlug(),
            authorName: $authorName,
            authorAvatarUrl: $avatarUrl,
            authorId: $authorId,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private function resolveAuthor(?User $user, ?string $fallbackName = null): array
    {
        if (!$user instanceof User) {
            return [$fallbackName, null, null];
        }

        $name = $user->getPublicDisplayName();
        if ($name === '') {
            $name = (string) ($fallbackName ?? '');
        }

        $avatarUrl = null;
        $assetId = $user->getAvatarAssetId();
        if ($assetId !== null) {
            $asset = $this->assetRepository->find($assetId);
            if ($asset !== null && $asset->getStorageKey() !== null) {
                $avatarUrl = '/uploads/'.$asset->getStorageKey();
            }
        }

        return [$name !== '' ? $name : $fallbackName, $avatarUrl, $user->getId()];
    }

    private function countRecentAcrossSources(string $locale, \DateTimeImmutable $since): int
    {
        $count = 0;
        if ($this->isEnabled('roadmap.native_enabled', true)) {
            $count += $this->entryRepository->countRecentPublic($locale, $since);
        }

        foreach ($this->collectFeedItems($locale, null) as $item) {
            if ($item->source === RoadmapFeedItem::SOURCE_NATIVE) {
                continue;
            }
            if ($item->occurredAt >= $since) {
                ++$count;
            }
        }

        return $count;
    }

    private function isEnabled(string $key, bool $default): bool
    {
        $raw = $this->settingsRegistry->get($key, $default ? '1' : '0');

        return $raw === true || $raw === 1 || $raw === '1';
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null || $status === '' || $status === 'all') {
            return null;
        }

        return \in_array($status, RoadmapEntry::STATUSES, true) ? $status : null;
    }

    /**
     * @return list<int>
     */
    private function parseUserIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }
}
