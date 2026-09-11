<?php

declare(strict_types=1);

namespace App\Core\Aacp;

/**
 * Plain data for one AACP System Monitor card; mapped by aacp/system.html.twig to twig:cp:card/gauge.
 * Providers supply display data only — no Twig/HTML knowledge.
 */
final class SystemWidgetData
{
    /**
     * @param string               $title           card title
     * @param int|float            $value           primary displayed number
     * @param string|null          $unit            Unit suffix (e.g. MiB, %).
     * @param string|null          $description     short footer description
     * @param string|null          $linkRoute       detail link route name
     * @param array<string, mixed> $linkRouteParams route parameters for $linkRoute
     * @param 'default'|'danger'   $variant         twig:cp:card variant ('danger' = red emphasis)
     * @param float|null           $gaugeMax        when set, renders twig:cp:gauge at $value/$gaugeMax
     */
    public function __construct(
        public readonly string $title,
        public readonly int|float $value,
        public readonly ?string $unit = null,
        public readonly ?string $description = null,
        public readonly ?string $linkRoute = null,
        public readonly array $linkRouteParams = [],
        public readonly string $variant = 'default',
        public readonly ?float $gaugeMax = null,
    ) {
    }
}
