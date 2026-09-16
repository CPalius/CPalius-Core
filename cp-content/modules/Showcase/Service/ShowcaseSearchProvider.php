<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Search\SearchGroup;
use App\Core\Search\SearchHit;
use App\Core\Search\SearchProviderInterface;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Puts published showcase entries into the site-wide search box, so a visitor
 * searching for a product name finds the listing next to the blog post and the
 * forum thread about it.
 *
 * Core never imports this class: it is discovered through the
 * cpalius.search.provider tag on SearchProviderInterface, and a deactivated
 * Showcase module simply stops contributing a group.
 */
final class ShowcaseSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcasePresenter $presenter,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKey(): string
    {
        return 'showcase';
    }

    public function getLabel(): string
    {
        return 'showcase.search.group_label';
    }

    public function getIcon(): string
    {
        return 'bi-grid-3x3-gap';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function search(string $term, string $locale, int $limit): SearchGroup
    {
        $hits = [];

        foreach ($this->items->searchVisible($term, $locale, $limit) as $item) {
            if (!$item instanceof ShowcaseItem) {
                continue;
            }

            $url = $this->safeUrl('showcase_show', ['_locale' => $locale, 'slug' => $item->getSlug()]);

            if ($url === null) {
                continue;
            }

            $hits[] = new SearchHit(
                title: $item->getTitle(),
                url: $url,
                excerpt: $this->presenter->excerpt($item, 140),
                date: $item->getPublishedAt(),
            );
        }

        return new SearchGroup(
            key: $this->getKey(),
            label: $this->getLabel(),
            icon: $this->getIcon(),
            hits: $hits,
            total: \count($hits),
            moreUrl: $hits !== [] ? $this->safeUrl('showcase_index', ['_locale' => $locale, 'q' => $term]) : null,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function safeUrl(string $route, array $params): ?string
    {
        try {
            return $this->urlGenerator->generate($route, $params);
        } catch (RoutingException) {
            return null;
        }
    }
}
