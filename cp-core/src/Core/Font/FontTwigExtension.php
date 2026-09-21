<?php

declare(strict_types=1);

namespace App\Core\Font;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the generated stylesheet to a layout.
 *
 * A function rather than a global so the is_file() check happens only on pages
 * that ask, and so a theme can decide where in its head the link belongs.
 */
final class FontTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly FontStylesheetBuilder $stylesheet,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_font_stylesheet', $this->stylesheetPath(...)),
        ];
    }

    /**
     * @return string|null public path, or null when nothing has been generated
     */
    public function stylesheetPath(): ?string
    {
        try {
            if (!$this->stylesheet->exists()) {
                return null;
            }

            // Cache-bust on content: an operator who changes the body face and
            // still sees the old one will conclude the feature does not work.
            $version = @filemtime($this->stylesheet->filePath());

            return $this->stylesheet->publicPath().($version !== false ? '?v='.$version : '');
        } catch (\Throwable) {
            return null;
        }
    }
}
