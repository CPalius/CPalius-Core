<?php

declare(strict_types=1);

namespace App\Core\Search\Admin;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Aggregates admin search providers. A failing provider is skipped, never a 500 in the header.
 */
final class AdminSearchService
{
    /**
     * @param iterable<AdminSearchProviderInterface> $providers
     */
    public function __construct(
        #[TaggedIterator('cpalius.admin_search.provider')]
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, icon: string, hits: list<array{title: string, url: string, subtitle: ?string}>}>
     */
    public function search(string $term, string $panel, int $limitPerGroup = 6): array
    {
        $providers = [];

        foreach ($this->providers as $provider) {
            if ($provider instanceof AdminSearchProviderInterface) {
                $providers[] = $provider;
            }
        }

        usort($providers, static fn (AdminSearchProviderInterface $a, AdminSearchProviderInterface $b): int => $a->getPriority() <=> $b->getPriority());

        $groups = [];

        foreach ($providers as $provider) {
            try {
                $group = $provider->search($term, $panel, $limitPerGroup);
            } catch (\Throwable) {
                continue;
            }

            if ($group !== null && $group['hits'] !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
