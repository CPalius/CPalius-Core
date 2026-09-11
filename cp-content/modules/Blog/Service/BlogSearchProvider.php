<?php

declare(strict_types=1);

namespace Modules\Blog\Service;

use App\Core\Search\SearchGroup;
use App\Core\Search\SearchHit;
use App\Core\Search\SearchProviderInterface;
use App\Core\Search\SearchText;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Published blog posts for the header global search.
 */
final class BlogSearchProvider implements SearchProviderInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'blog';
    }

    public function getLabel(): string
    {
        return 'site.search.source.blog';
    }

    public function getIcon(): string
    {
        return 'bi-journal-richtext';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function search(string $term, string $locale, int $limit): SearchGroup
    {
        $nodes = $this->nodeRepository
            ->createSearchQueryBuilder($term, self::NODE_TYPE, $locale)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $hits = [];
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $url = $this->postUrl($node, $locale);
            if ($url === null) {
                continue;
            }
            $hits[] = new SearchHit(
                title: $node->getTitle(),
                url: $url,
                excerpt: SearchText::snippet((string) $node->getDataValue('excerpt', '')),
                date: $node->getPublishedAt(),
            );
        }

        return new SearchGroup(
            key: $this->getKey(),
            label: $this->getLabel(),
            icon: $this->getIcon(),
            hits: $hits,
            total: \count($hits),
            moreUrl: $hits !== [] ? $this->safeUrl('blog_search', ['_locale' => $locale, 'q' => $term]) : null,
        );
    }

    private function postUrl(Node $node, string $locale): ?string
    {
        return $this->safeUrl('blog_show', ['_locale' => $locale, 'slug' => $node->getSlug()]);
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
