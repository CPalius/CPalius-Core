<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\DBAL\Connection;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Modules\Showcase\Repository\ShowcaseReviewRepository;
use Modules\Showcase\Repository\ShowcaseTypeRepository;

/**
 * Numbers for the Showcase admin overview.
 *
 * Aggregates run as single grouped queries through DBAL rather than counting in
 * PHP over hydrated entities: a dashboard that loads every listing to count them
 * gets slower exactly as the site it describes gets busier.
 */
final class ShowcaseStatsService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseTypeRepository $types,
        private readonly ShowcaseReviewRepository $reviews,
        private readonly FieldDefinitionRepository $fieldDefinitions,
    ) {
    }

    /**
     * @return array{
     *     total: int, published: int, pending: int, draft: int, rejected: int, archived: int,
     *     featured: int, trashed: int, views: int, clicks: int, reviews: int, pendingReviews: int,
     *     types: int, enabledTypes: int, fields: int, avgRating: ?float
     * }
     */
    public function overview(): array
    {
        $byStatus = $this->items->countByStatus();
        $totals = $this->scalarTotals();
        $typeRows = $this->types->findAllOrdered();

        $fields = 0;
        $enabledTypes = 0;

        foreach ($typeRows as $type) {
            $fields += \count($this->fieldDefinitions->findByBundle($type->fieldBundle()));

            if ($type->isEnabled()) {
                ++$enabledTypes;
            }
        }

        return [
            'total' => array_sum($byStatus),
            'published' => $byStatus[ShowcaseItem::STATUS_PUBLISHED] ?? 0,
            'pending' => $byStatus[ShowcaseItem::STATUS_PENDING] ?? 0,
            'draft' => $byStatus[ShowcaseItem::STATUS_DRAFT] ?? 0,
            'rejected' => $byStatus[ShowcaseItem::STATUS_REJECTED] ?? 0,
            'archived' => $byStatus[ShowcaseItem::STATUS_ARCHIVED] ?? 0,
            'featured' => $totals['featured'],
            'trashed' => $totals['trashed'],
            'views' => $totals['views'],
            'clicks' => $totals['clicks'],
            'reviews' => $totals['reviews'],
            'pendingReviews' => $this->reviews->countPending(),
            'types' => \count($typeRows),
            'enabledTypes' => $enabledTypes,
            'fields' => $fields,
            'avgRating' => $totals['avgRating'],
        ];
    }

    /**
     * Item counts per type, for the overview chart and the type table.
     *
     * @return list<array{machineName: string, label: string, total: int, published: int, fields: int}>
     */
    public function perType(string $locale): array
    {
        $counts = $this->countsByType();
        $rows = [];

        foreach ($this->types->findAllOrdered() as $type) {
            $id = (int) $type->getId();

            $rows[] = [
                'machineName' => $type->getMachineName(),
                'label' => $type->label($locale),
                'total' => $counts[$id]['total'] ?? 0,
                'published' => $counts[$id]['published'] ?? 0,
                'fields' => \count($this->fieldDefinitions->findByBundle($type->fieldBundle())),
            ];
        }

        return $rows;
    }

    /**
     * New items per day for the last N days, gap-filled so the chart shows a
     * flat line on quiet days instead of skipping them and implying activity.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function dailyActivity(int $days = 30): array
    {
        $days = max(7, min(90, $days));
        $since = (new \DateTimeImmutable())->modify('-' . ($days - 1) . ' days')->setTime(0, 0);

        $rows = [];

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT DATE(created_at) AS day, COUNT(*) AS total
                 FROM cp_showcase_items
                 WHERE created_at >= :since
                 GROUP BY DATE(created_at)',
                ['since' => $since->format('Y-m-d H:i:s')],
            );
        } catch (\Throwable) {
            // A dashboard chart is never worth a 500 on the page around it.
            $rows = [];
        }

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['total'];
        }

        $labels = [];
        $values = [];

        for ($i = 0; $i < $days; ++$i) {
            $day = $since->modify('+' . $i . ' days')->format('Y-m-d');
            $labels[] = $day;
            $values[] = $byDay[$day] ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return array{featured: int, trashed: int, views: int, clicks: int, reviews: int, avgRating: ?float}
     */
    private function scalarTotals(): array
    {
        $empty = ['featured' => 0, 'trashed' => 0, 'views' => 0, 'clicks' => 0, 'reviews' => 0, 'avgRating' => null];

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT
                    COALESCE(SUM(CASE WHEN featured = 1 AND deleted_at IS NULL THEN 1 ELSE 0 END), 0) AS featured,
                    COALESCE(SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS trashed,
                    COALESCE(SUM(view_count), 0) AS views,
                    COALESCE(SUM(click_count), 0) AS clicks,
                    COALESCE(SUM(rating_count), 0) AS rating_count,
                    COALESCE(SUM(rating_sum), 0) AS rating_sum
                 FROM cp_showcase_items',
            );
        } catch (\Throwable) {
            return $empty;
        }

        if ($row === false) {
            return $empty;
        }

        $ratingCount = (int) $row['rating_count'];

        return [
            'featured' => (int) $row['featured'],
            'trashed' => (int) $row['trashed'],
            'views' => (int) $row['views'],
            'clicks' => (int) $row['clicks'],
            'reviews' => $ratingCount,
            // Null rather than 0 when nobody has rated: "0.0 out of 5" reads as
            // a terrible score, not as an absent one.
            'avgRating' => $ratingCount > 0 ? round((int) $row['rating_sum'] / $ratingCount, 1) : null,
        ];
    }

    /**
     * @return array<int, array{total: int, published: int}>
     */
    private function countsByType(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT type_id,
                        COUNT(*) AS total,
                        COALESCE(SUM(CASE WHEN status = :published THEN 1 ELSE 0 END), 0) AS published
                 FROM cp_showcase_items
                 WHERE deleted_at IS NULL
                 GROUP BY type_id',
                ['published' => ShowcaseItem::STATUS_PUBLISHED],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['type_id']] = [
                'total' => (int) $row['total'],
                'published' => (int) $row['published'],
            ];
        }

        return $out;
    }
}
