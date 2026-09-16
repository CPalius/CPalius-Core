<?php

declare(strict_types=1);

namespace Modules\Showcase\Query;

use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;

/**
 * Everything a showcase listing can be narrowed by, in one immutable object.
 *
 * It exists so the repository never receives a raw request bag: each value is
 * coerced once, here, and the query builder can then trust its own input. The
 * custom-field criteria are the interesting part — they are the reason a car
 * listing and a SaaS listing can use the same repository method.
 */
final class ShowcaseFilter
{
    public const SORT_RECENT = 'recent';
    public const SORT_OLDEST = 'oldest';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORT_POPULAR = 'popular';
    public const SORT_RATING = 'rating';
    public const SORT_TITLE = 'title';

    public const SORTS = [
        self::SORT_RECENT,
        self::SORT_OLDEST,
        self::SORT_PRICE_ASC,
        self::SORT_PRICE_DESC,
        self::SORT_POPULAR,
        self::SORT_RATING,
        self::SORT_TITLE,
    ];

    /**
     * @param list<string>                                                             $statuses
     * @param list<array{field: string, kind: string, op: string, value: mixed}>       $fieldCriteria
     */
    public function __construct(
        public readonly string $locale,
        public readonly ?ShowcaseType $type = null,
        public readonly array $statuses = [ShowcaseItem::STATUS_PUBLISHED],
        public readonly ?int $termId = null,
        public readonly ?string $search = null,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly ?int $ownerId = null,
        public readonly bool $featuredOnly = false,
        public readonly array $fieldCriteria = [],
        public readonly string $sort = self::SORT_RECENT,
        public readonly bool $includeExpired = false,
        public readonly bool $includeTrashed = false,
        public readonly bool $anyLocale = false,
    ) {
    }

    public static function normalizeSort(mixed $raw): string
    {
        $sort = \is_string($raw) ? strtolower(trim($raw)) : '';

        return \in_array($sort, self::SORTS, true) ? $sort : self::SORT_RECENT;
    }

}
