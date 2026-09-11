<?php

declare(strict_types=1);

namespace App\Core\Token\Twig;

use App\Core\Token\TokenReplacer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TokenTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly TokenReplacer $tokenReplacer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('token_replace', $this->tokenReplacer->replace(...)),
        ];
    }
}
