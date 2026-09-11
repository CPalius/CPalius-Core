<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Twig;

use App\Core\TextFormat\TextFormatProcessor;
use App\Core\TextFormat\TextFormatRegistry;
use App\Core\Token\TokenContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ markdown_or_html|text_format('markdown') }}` — modules that store
 * bodies outside Field API (Forum, user bio) can still run the pipeline.
 */
final class TextFormatTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly TextFormatProcessor $processor,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('text_format', $this->format(...), ['is_safe' => ['html']]),
        ];
    }

    public function format(string $text, string $format = TextFormatRegistry::BASIC_HTML, mixed $subject = null): string
    {
        return $this->processor->processForDisplay($text, $format, TokenContext::for($subject));
    }
}
