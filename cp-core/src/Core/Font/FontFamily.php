<?php

declare(strict_types=1);

namespace App\Core\Font;

/**
 * An installed typeface: the folder, the name a stylesheet refers to it by,
 * and the files it is made of.
 */
final readonly class FontFamily
{
    /**
     * @param list<FontFace> $faces
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $faces,
        public ?string $source = null,
        public ?string $installedAt = null,
        public int $bytes = 0,
    ) {
    }

    /** The value that goes into a font-family declaration, quoted. */
    public function cssName(): string
    {
        return '"'.str_replace('"', '', $this->name).'"';
    }

    /**
     * @return list<string>
     */
    public function weights(): array
    {
        $weights = [];

        foreach ($this->faces as $face) {
            $label = $face->label();
            if (!\in_array($label, $weights, true)) {
                $weights[] = $label;
            }
        }

        return $weights;
    }

    public function fileCount(): int
    {
        return \count($this->faces);
    }
}
