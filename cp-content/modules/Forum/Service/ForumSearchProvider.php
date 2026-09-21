<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Search\SearchGroup;
use App\Core\Search\SearchHit;
use App\Core\Search\SearchProviderInterface;
use App\Core\Search\SearchText;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumTopic;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Forum topics and posts for the header global search (current locale boards only).
 */
final class ForumSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly ForumSearchService $searchService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ForumGuestView $guestView,
    ) {
    }

    public function getKey(): string
    {
        return 'forum';
    }

    public function getLabel(): string
    {
        return 'site.search.source.forum';
    }

    public function getIcon(): string
    {
        return 'bi-chat-square-text';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function search(string $term, string $locale, int $limit): SearchGroup
    {
        $hideBodies = $this->guestView->hidePostBodies();
        $results = $this->searchService->search($term, $hideBodies ? 'topics' : 'all', null, $limit, $locale);
        $hits = [];
        $seenTopicIds = [];

        foreach ($results['topics'] as $topic) {
            if (!$topic instanceof ForumTopic || $topic->getId() === null) {
                continue;
            }
            $url = $this->topicUrl($topic, $locale);
            if ($url === null) {
                continue;
            }
            $hits[] = new SearchHit(
                title: $topic->getTitle(),
                url: $url,
                excerpt: SearchText::snippet($topic->getDescription()),
                date: $topic->getUpdatedAt(),
            );
            $seenTopicIds[$topic->getId()] = true;
            if (\count($hits) >= $limit) {
                break;
            }
        }

        if (!$hideBodies && \count($hits) < $limit) {
            foreach ($results['posts'] as $post) {
                if (!$post instanceof ForumPost) {
                    continue;
                }
                $topic = $post->getTopic();
                $topicId = $topic->getId();
                if ($topicId !== null && isset($seenTopicIds[$topicId])) {
                    continue;
                }
                $url = $this->postUrl($post, $locale);
                if ($url === null) {
                    continue;
                }
                $hits[] = new SearchHit(
                    title: $topic->getTitle(),
                    url: $url,
                    excerpt: SearchText::snippet($post->getBody()),
                    date: $post->getCreatedAt(),
                );
                if ($topicId !== null) {
                    $seenTopicIds[$topicId] = true;
                }
                if (\count($hits) >= $limit) {
                    break;
                }
            }
        }

        $total = $results['totalTopics'] + ($hideBodies ? 0 : $results['totalPosts']);

        return new SearchGroup(
            key: $this->getKey(),
            label: $this->getLabel(),
            icon: $this->getIcon(),
            hits: $hits,
            total: max($total, \count($hits)),
            moreUrl: $hits !== [] ? $this->safeUrl('forum_search', ['_locale' => $locale, 'q' => $term]) : null,
        );
    }

    private function topicUrl(ForumTopic $topic, string $locale, ?int $postId = null): ?string
    {
        $params = [
            '_locale' => $topic->getLocale() !== '' ? $topic->getLocale() : $locale,
            'topicId' => $topic->getId(),
            'slug' => $topic->getSlug() ?? '',
        ];
        if ($postId !== null) {
            $params['_fragment'] = 'post'.$postId;
        }

        return $this->safeUrl('forum_topic', $params);
    }

    private function postUrl(ForumPost $post, string $locale): ?string
    {
        return $this->topicUrl($post->getTopic(), $locale, $post->getId());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function safeUrl(string $route, array $params): ?string
    {
        try {
            return $this->urlGenerator->generate($route, $params);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
