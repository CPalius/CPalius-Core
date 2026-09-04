<?php

declare(strict_types=1);

namespace App\Core\Seo\Twig;

use App\Core\Seo\SeoHeadRendererInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Prefers the Seo module renderer; otherwise title/description/robots + hreflang.
 */
final class SeoHeadRuntime implements RuntimeExtensionInterface
{
    /**
     * @param iterable<SeoHeadRendererInterface> $renderers
     */
    public function __construct(
        private readonly Environment $twig,
        #[TaggedIterator('cpalius.seo.head_renderer')]
        private readonly iterable $renderers = [],
    ) {
    }

    /**
     * @param array{title?: string, description?: string, robots?: string} $overrides
     */
    public function render(array $overrides = []): string
    {
        foreach ($this->renderers as $renderer) {
            return $renderer->render($overrides);
        }

        return $this->twig->render('seo/fallback_head.html.twig', [
            'title' => trim((string) ($overrides['title'] ?? '')),
            'description' => trim((string) ($overrides['description'] ?? '')),
            'robots' => trim((string) ($overrides['robots'] ?? 'index, follow')) ?: 'index, follow',
        ]);
    }
}
