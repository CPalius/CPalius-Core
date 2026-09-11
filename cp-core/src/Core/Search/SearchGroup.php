<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * One module's slice of global search results.
 */
final readonly class SearchGroup
{
    /**
     * @param list<SearchHit> $hits
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public array $hits,
        public int $total,
        public ?string $moreUrl = null,
    ) {
    }
}
