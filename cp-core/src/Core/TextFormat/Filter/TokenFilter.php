<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Filter;

use App\Core\TextFormat\TextFilterContext;
use App\Core\TextFormat\TextFilterInterface;
use App\Core\Token\TokenReplacer;

/**
 * T2.4 payment: `[node:title]` / `[site:name]` etc. resolve inside formatted
 * text. Values are HTML-escaped so a title containing `<` cannot break out of
 * the surrounding markup (Drupal's Token filter emits raw replacements).
 */
final class TokenFilter implements TextFilterInterface
{
    public function __construct(
        private readonly TokenReplacer $tokenReplacer,
    ) {
    }

    public function id(): string
    {
        return 'token';
    }

    public function phases(): array
    {
        return [TextFilterContext::PHASE_OUTPUT];
    }

    public function process(string $text, TextFilterContext $context): string
    {
        return $this->tokenReplacer->replace($text, $context->tokenContext, true);
    }
}
