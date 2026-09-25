<?php

declare(strict_types=1);

namespace App\Core\Analytics;

/**
 * Core's read-side extension point, matching VisitorRecorderInterface — the
 * AACP dashboard depends only on this interface, injected as optional
 * (nullable), so it renders correctly (a "not installed" hint instead of
 * numbers) whether or not the VisitorStats module is present.
 */
interface VisitorStatsProviderInterface
{
    /**
     * @return array{uniqueIps: int, pageViews: int, hourly: array{labels: list<string>, hits: list<int>}}
     */
    public function stats(int $hours = 24): array;
}
