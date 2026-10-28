<?php

declare(strict_types=1);

namespace App\Core\Theme;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AppearanceTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly SiteAppearance $appearance,
        private readonly StudioAppearance $studio,
        private readonly ThemeRegistry $themes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_appearance', $this->appearance->read(...)),
            new TwigFunction('cp_appearance_css', $this->appearance->css(...)),
            new TwigFunction('cp_studio_appearance', $this->studio->read(...)),
            // cp_theme_url('dist/style.css'): versioned URL from the active theme, else any installed one.
            new TwigFunction('cp_theme_url', fn (string $path): string => $this->themes->assetUrl($path) ?? ''),
        ];
    }
}
