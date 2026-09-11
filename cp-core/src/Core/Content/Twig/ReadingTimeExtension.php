<?php

declare(strict_types=1);

namespace App\Core\Content\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Same pattern as SchemaOrgExtension: registers the filter only; logic lives in ReadingTimeRuntime (lazy-loaded).
 */
final class ReadingTimeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('reading_time', [ReadingTimeRuntime::class, 'calculate']),
        ];
    }
}
