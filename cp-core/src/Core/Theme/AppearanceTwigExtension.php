<?php

declare(strict_types=1);

namespace App\Core\Theme;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AppearanceTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly SiteAppearance $appearance,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_appearance', $this->appearance->read(...)),
            new TwigFunction('cp_appearance_css', $this->appearance->css(...)),
        ];
    }
}
