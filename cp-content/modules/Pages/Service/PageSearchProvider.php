<?php

declare(strict_types=1);

namespace Modules\Pages\Service;

use App\Core\Search\SearchGroup;
use App\Core\Search\SearchHit;
use App\Core\Search\SearchProviderInterface;
use App\Core\Search\SearchText;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Modules\Pages\Controller\Admin\PageAdminController;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PageSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'pages';
    }

    public function getLabel(): string
    {
        return 'site.search.source.pages';
    }

    public function getIcon(): string
    {
        return 'bi-file-earmark-text';
    }

    public function getPriority(): int
    {
        return 8;
    }

    public function search(string $term, string $locale, int $limit): SearchGroup
    {
        $nodes = $this->nodeRepository
            ->createSearchQueryBuilder($term, PageAdminController::NODE_TYPE, $locale)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $hits = [];
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $url = $this->pageUrl($node, $locale);
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
            moreUrl: null,
        );
    }

    private function pageUrl(Node $node, string $locale): ?string
    {
        try {
            return $this->urlGenerator->generate('page_show', ['_locale' => $locale, 'slug' => $node->getSlug()]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
