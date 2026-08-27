<?php

namespace App\Core\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Zengin metin editöründen (Jodit) gelen HTML'i persist edilmeden hemen
 * önce temizler (Manifesto Law 5.3). Media modülünden bağımsız, generic
 * bir core servis — herhangi bir Node type'ında rich-text alanı olan
 * modül (Blog, Portfolio vb.) bunu kullanabilir.
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
