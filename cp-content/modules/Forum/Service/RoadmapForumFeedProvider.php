<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use App\Repository\AssetRepository;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Roadmap\Contract\RoadmapForumFeedProviderInterface;
use Modules\Roadmap\Dto\RoadmapFeedItem;
use Modules\Roadmap\Entity\RoadmapEntry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RoadmapForumFeedProvider implements RoadmapForumFeedProviderInterface
{
    public function __construct(
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumTopicRepository $topicRepository,
        private readonly AssetRepository $assetRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<RoadmapFeedItem>
     */
    public function fetchTopics(string $locale, int $sectionId, int $limit, array $userIds = []): array
    {
        if ($sectionId <= 0 || $limit <= 0) {
            return [];
        }

        $section = $this->sectionRepository->find($sectionId);
        if (!$section instanceof ForumSection) {
            return [];
        }

        $qb = $this->topicRepository->createSectionTopicsQueryBuilder($section, null, true);
        if ($userIds !== []) {
            $qb->andWhere('t.firstPoster IN (:uids)')->setParameter('uids', $userIds);
        }

        $topics = $qb
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($topics as $topic) {
            if (!$topic instanceof ForumTopic) {
                continue;
            }
            $items[] = $this->mapTopic($topic, $locale);
        }

        return $items;
    }

    private function mapTopic(ForumTopic $topic, string $locale): RoadmapFeedItem
    {
        $author = $topic->getFirstPoster();
        [$authorName, $avatarUrl, $authorId] = $this->resolveAuthor(
            $author,
            $topic->getFirstPosterName(),
        );

        return new RoadmapFeedItem(
            source: RoadmapFeedItem::SOURCE_FORUM,
            title: $topic->getTitle(),
            excerpt: (string) ($topic->getDescription() ?? $topic->getPreview() ?? ''),
            url: $this->urlGenerator->generate('forum_topic', [
                '_locale' => $locale,
                'topicId' => $topic->getId(),
                'slug' => $topic->getSlug() ?? '',
            ]),
            occurredAt: $topic->getUpdatedAt(),
            status: RoadmapEntry::STATUS_SHIPPED,
            badge: 'Forum',
            icon: 'bi-chat-dots',
            slug: $topic->getSlug(),
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

        $name = $user->getFullName();
        if (trim($name) === '') {
            $name = (string) ($user->getDataValue('display_name') ?? $user->getEmail() ?? $fallbackName ?? '');
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
}
