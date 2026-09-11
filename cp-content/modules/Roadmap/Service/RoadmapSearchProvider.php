<?php

declare(strict_types=1);

namespace Modules\Roadmap\Service;

use App\Core\Search\SearchGroup;
use App\Core\Search\SearchHit;
use App\Core\Search\SearchProviderInterface;
use App\Core\Search\SearchText;
use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Public roadmap entries for the header global search.
 */
final class RoadmapSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly RoadmapEntryRepository $entryRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'roadmap';
    }

    public function getLabel(): string
    {
        return 'site.search.source.roadmap';
    }

    public function getIcon(): string
    {
        return 'bi-signpost-2';
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function search(string $term, string $locale, int $limit): SearchGroup
    {
        $entries = $this->entryRepository->searchPublic($term, $locale, $limit);
        $hits = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof RoadmapEntry) {
                continue;
            }
            $url = $this->safeUrl('roadmap_show', ['_locale' => $locale, 'slug' => $entry->getSlug()]);
            if ($url === null) {
                continue;
            }
            $hits[] = new SearchHit(
                title: $entry->getTitle(),
                url: $url,
                excerpt: SearchText::snippet($entry->getSummary()),
                date: $entry->getPublishedAt(),
            );
        }

        return new SearchGroup(
            key: $this->getKey(),
            label: $this->getLabel(),
            icon: $this->getIcon(),
            hits: $hits,
            total: \count($hits),
            moreUrl: $hits !== [] ? $this->safeUrl('roadmap_index', ['_locale' => $locale]) : null,
        );
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
