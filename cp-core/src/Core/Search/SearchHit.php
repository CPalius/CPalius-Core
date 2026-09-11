<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * One row on the global search results page. Providers map their entities here
 * so the theme never imports Modules\*.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $excerpt = null,
        public ?\DateTimeImmutable $date = null,
    ) {
    }
}
