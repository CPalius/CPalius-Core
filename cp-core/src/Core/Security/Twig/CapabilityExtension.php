<?php

declare(strict_types=1);

namespace App\Core\Security\Twig;

use App\Core\Security\CapabilityLabeler;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class CapabilityExtension extends AbstractExtension
{
    public function __construct(
        private readonly CapabilityLabeler $labels,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_capability', $this->labels->label(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('cp_capability', $this->labels->label(...)),
        ];
    }
}
