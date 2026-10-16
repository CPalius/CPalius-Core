<?php

declare(strict_types=1);

namespace App\Core\Search\Admin;

/**
 * Contributes one result group to the AACP and Studio header live search.
 * Tagged cpalius.admin_search.provider automatically; modules just implement it.
 */
interface AdminSearchProviderInterface
{
    /** Lower values are listed first. */
    public function getPriority(): int;

    /**
     * @param string $panel "aacp" or "studio"
     *
     * @return array{key: string, label: string, icon: string, hits: list<array{title: string, url: string, subtitle: ?string}>}|null null when this provider has nothing for the panel or the user
     */
    public function search(string $term, string $panel, int $limit): ?array;
}
