<?php

declare(strict_types=1);

namespace App\Core\Seo\Twig;

use App\Core\Seo\SeoHeadRendererInterface;
use App\Core\Settings\SettingsRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private readonly SettingsRegistry $settings,
        private readonly RequestStack $requestStack,
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

        // No Seo module: pages that name nothing themselves fall back to the
        // site-wide title and description from Settings.
        $locale = (string) $this->requestStack->getCurrentRequest()?->getLocale();

        return $this->twig->render('seo/fallback_head.html.twig', [
            'title' => trim((string) ($overrides['title'] ?? '')) ?: trim((string) $this->settings->getForLocale('core.site_name', $locale, 'CPalius CMF')),
            'description' => trim((string) ($overrides['description'] ?? '')) ?: trim((string) $this->settings->getForLocale('core.default_meta_description', $locale, '')),
            'robots' => trim((string) ($overrides['robots'] ?? 'index, follow')) ?: 'index, follow',
        ]);
    }
}
