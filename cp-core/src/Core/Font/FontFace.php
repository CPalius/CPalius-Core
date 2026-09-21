<?php

declare(strict_types=1);

namespace App\Core\Font;

/**
 * One file of a family: a weight and a style, and where the browser gets it.
 */
final readonly class FontFace
{
    public function __construct(
        public string $file,
        public string $url,
        public string $format,
        public string $weight,
        public string $style,
        public ?string $unicodeRange = null,
    ) {
    }

    public function label(): string
    {
        return $this->weight.($this->style === 'italic' ? ' italic' : '');
    }
}
