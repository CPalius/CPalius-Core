<?php

declare(strict_types=1);

namespace App\Core\Content\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * SchemaOrgExtension (cp-core/src/Core/Content/Twig/SchemaOrgExtension.php)
 * ile aynı desen: bu sınıf SADECE filtre kaydı yapar, asıl mantık
 * ReadingTimeRuntime'da (RuntimeExtensionInterface, lazy-loaded) yaşar.
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
