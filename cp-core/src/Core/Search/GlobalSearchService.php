<?php

declare(strict_types=1);

namespace App\Core\Search;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Throwable;

/**
 * Aggregates tagged module search providers. One provider failing does not 500 the page.
 */
final class GlobalSearchService
{
    /**
     * @param iterable<SearchProviderInterface> $providers
     */
    public function __construct(
        #[TaggedIterator('cpalius.search.provider')]
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return list<SearchGroup>
     */
    public function search(string $term, string $locale, int $limitPerSource = 8): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $groups = [];
        foreach ($this->sortedProviders() as $provider) {
            try {
                $group = $provider->search($term, $locale, $limitPerSource);
            } catch (Throwable) {
                continue;
            }

            if ($group->hits === []) {
                continue;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @return list<SearchProviderInterface>
     */
    private function sortedProviders(): array
    {
        $providers = [];
        foreach ($this->providers as $provider) {
            if ($provider instanceof SearchProviderInterface) {
                $providers[] = $provider;
            }
        }

        usort(
            $providers,
            static fn (SearchProviderInterface $a, SearchProviderInterface $b): int => $a->getPriority() <=> $b->getPriority(),
        );

        return $providers;
    }
}
