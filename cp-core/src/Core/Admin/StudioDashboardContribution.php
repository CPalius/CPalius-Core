<?php

declare(strict_types=1);

namespace App\Core\Admin;

/**
 * Module contribution to the Studio command desk. Metrics only — no Twig/HTML.
 */
final class StudioDashboardContribution
{
    /**
     * @param list<array{key: string, labelKey: string, count: int}> $mixItems
     * @param list<array{key: string, labelKey: string, value: int|string}> $extraKpis
     * @param list<array{
     *     title: string,
     *     typeKey: string,
     *     author: string,
     *     updatedAt: \DateTimeImmutable,
     *     status: string,
     *     editRoute: ?string,
     *     editParams: array<string, mixed>,
     *     viewRoute: ?string,
     *     viewParams: array<string, mixed>
     * }> $recentActivity
     */
    public function __construct(
        public readonly int $publishedCount = 0,
        public readonly int $draftCount = 0,
        public readonly int $mediaBytes = 0,
        public readonly int $forumPostsLast24h = 0,
        public readonly array $extraKpis = [],
        public readonly array $mixItems = [],
        public readonly array $recentActivity = [],
    ) {
    }
}
