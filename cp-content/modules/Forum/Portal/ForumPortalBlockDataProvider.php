<?php

declare(strict_types=1);

namespace Modules\Forum\Portal;

use App\Core\Portal\PortalBlockDataProviderInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumPostRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;

/**
 * Homepage forum portal blocks — extracted from ThemeController.
 */
final class ForumPortalBlockDataProvider implements PortalBlockDataProviderInterface
{
    /** @var list<string> */
    private const BLOCK_IDS = [
        'latest_forum_topics',
        'popular_forum_topics',
        'latest_forum_posts',
        'forum_boards',
        'forum_stats',
    ];

    public function __construct(
        private readonly ForumTopicRepository $forumTopicRepository,
        private readonly ForumPostRepository $forumPostRepository,
        private readonly ForumSectionRepository $forumSectionRepository,
    ) {
    }

    public function supports(string $blockId): bool
    {
        return \in_array($blockId, self::BLOCK_IDS, true);
    }

    public function provide(string $blockId, array $block, string $locale): ?array
    {
        $limit = (int) ($block['limit'] ?? 5);

        return match ($blockId) {
            'latest_forum_topics' => $this->wrapItems($this->forumTopicRepository->findLatest($limit, 0, $locale)),
            'popular_forum_topics' => $this->wrapItems($this->forumTopicRepository->findPopular($limit, $locale)),
            'latest_forum_posts' => $this->wrapItems($this->forumPostRepository->findLatest($limit, $locale)),
            'forum_boards' => $this->wrapItems($this->loadForumBoards($locale, $limit)),
            'forum_stats' => $this->aggregateForumStats($locale),
            default => null,
        };
    }

    /**
     * @param list<mixed> $items
     *
     * @return array{items: list<mixed>}|null
     */
    private function wrapItems(array $items): ?array
    {
        return $items === [] ? null : ['items' => $items];
    }

    /**
     * @return list<ForumSection>
     */
    private function loadForumBoards(string $locale, int $limit): array
    {
        $sections = $this->forumSectionRepository->findAllByLocale($locale);
        $boards = array_values(array_filter(
            $sections,
            static fn (ForumSection $s): bool => $s->allowsTopics(),
        ));

        usort(
            $boards,
            static fn (ForumSection $a, ForumSection $b): int => $b->getPostCount() <=> $a->getPostCount()
                ?: $a->getSortOrder() <=> $b->getSortOrder(),
        );

        return array_slice($boards, 0, $limit);
    }

    /** @return array{topics: int, posts: int, sections: int} */
    private function aggregateForumStats(string $locale): array
    {
        $sections = $this->forumSectionRepository->findAllByLocale($locale);
        $topics = 0;
        $posts = 0;
        $boardCount = 0;

        foreach ($sections as $section) {
            $topics += $section->getTopicCount();
            $posts += $section->getPostCount();
            if ($section->allowsTopics()) {
                ++$boardCount;
            }
        }

        return [
            'topics' => $topics,
            'posts' => $posts,
            'sections' => $boardCount,
        ];
    }
}
