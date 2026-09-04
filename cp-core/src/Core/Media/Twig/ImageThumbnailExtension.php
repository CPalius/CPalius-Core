<?php

declare(strict_types=1);

namespace App\Core\Media\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Registers the {{ path|cp_thumb(w, h) }} filter used by themes and by the
 * CKEditor image pipeline to request dynamically resized derivatives.
 */
final class ImageThumbnailExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('cp_thumb', [ImageThumbnailRuntime::class, 'thumb']),
        ];
    }
}
