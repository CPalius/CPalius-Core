<?php

declare(strict_types=1);

namespace App\Core\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Sanitizes rich-text editor (Jodit) HTML before persistence (Manifesto Law 5.3).
 * Generic core service independent of Media; any module with rich-text Node fields may use it.
 */
final class RichTextSanitizer
{
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.cpalius_richtext')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
